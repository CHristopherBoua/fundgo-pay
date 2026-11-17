<?php

namespace App\Payments\Drivers;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Payments\Data\ChargeResult;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\MandateResult;
use App\Payments\Data\SepaMandateRequest;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\Omnipay\Ecobank\EcobankGateway;
use App\Payments\Omnipay\Ecobank\Message\EcobankResponse;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Ecobank « Direct Payment From Ecobank Account ».
 *
 * Parcours donateur (une seule fois) : OTP -> validation -> création du profil ->
 * code de l'appli Ecobank Authenticator -> profil actif.
 * Puis à chaque paiement : initiation -> OTP Authenticator -> validation.
 *
 * Aucun débit sans le donateur : les dons réguliers passent en « à régler par lien ».
 * Pas de webhook documenté pour ce service : le résultat de la validation fait foi.
 */
class EcobankDriver extends AbstractOmnipayDriver
{
    public function supports(PaymentMethodType $type): bool
    {
        return $type === PaymentMethodType::EcobankAccount;
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->config['currencies'] ?? ['XOF', 'XAF'], true);
    }

    public function supportsOffSession(PaymentMethodType $type): bool
    {
        return false;
    }

    /** @return EcobankGateway */
    protected function gateway(): EcobankGateway
    {
        $gateway = parent::gateway();
        $gateway->setBearerToken($this->bearerToken());

        return $gateway;
    }

    /** Le jeton Ecobank expire après ~5 min : cache court. */
    private function bearerToken(): string
    {
        return Cache::remember('ecobank:token:'.$this->name, (int) ($this->config['token_ttl'] ?? 240), function () {
            /** @var EcobankResponse $response */
            $response = $this->send(parent::gateway()->token(), 'token');
            $token = $response->get('access_token', 'accessToken', 'token');

            if (! $response->isSuccessful() || ! is_string($token) || $token === '') {
                throw new PaymentGatewayException('[ecobank] authentication failed: '.($response->getMessage() ?? 'no token'), $response->getCode());
            }

            return $token;
        });
    }

    /** Identifiant donateur chez Ecobank (userId du profil Direct Debit). */
    public function createCustomer(User $user): string
    {
        return ($this->config['user_id_prefix'] ?? 'FGP').$user->id;
    }

    public function createSepaMandate(User $user, string $customerRef, SepaMandateRequest $request): MandateResult
    {
        throw new PaymentGatewayException('[ecobank] SEPA is not supported');
    }

    /**
     * Pas de page hébergée chez Ecobank : on renvoie vers notre page qui enchaîne
     * l'enrôlement (si besoin) puis la saisie de l'OTP Ecobank Authenticator.
     */
    public function startHostedCheckout(Payment $payment, string $customerRef, string $returnUrl): CheckoutSession
    {
        return new CheckoutSession(route('ecobank.show', $payment->id), 'eco-'.$payment->id);
    }

    public function chargeOffSession(Payment $payment, PaymentMethod $method): ChargeResult
    {
        return ChargeResult::failed('authentication_required', 'Ecobank exige la validation du donateur (OTP) pour chaque débit');
    }

    public function fetchPayment(Payment $payment): ChargeResult
    {
        // Aucun endpoint de statut public pour ce service : le statut local fait foi.
        return new ChargeResult($payment->status, $payment->gateway_reference);
    }

    public function refund(Payment $payment, ?int $amountCents = null): ChargeResult
    {
        throw new PaymentGatewayException('[ecobank] refunds are not available through the Direct Debit API');
    }

    public function revokeMandate(PaymentMethod $method): void {}

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw new InvalidWebhookSignature('Ecobank Direct Debit has no documented webhook');
    }

    // ---------------------------------------------------------------- enrôlement

    /** @return string trackingId */
    public function sendOtp(string $userId, array $contact = []): string
    {
        $r = $this->call('sendOtp', array_filter(['userId' => $userId] + $contact));

        return (string) ($r->get('trackingId', 'tracking_id') ?? throw new PaymentGatewayException('[ecobank] no trackingId'));
    }

    public function validateOtp(string $code, string $trackingId): void
    {
        $this->call('validateOtp', ['code' => $code, 'trackingId' => $trackingId]);
    }

    /** @return string|null profileId */
    public function createProfile(string $userId, array $extra = []): ?string
    {
        $r = $this->call('createProfile', array_filter(['userId' => $userId] + $extra));

        return ($id = $r->get('profileId', 'objectId', 'id')) ? (string) $id : null;
    }

    /** Code généré par l'appli Ecobank Authenticator. */
    public function completeProfile(string $userId, string $authenticatorCode): void
    {
        $this->call('completeProfile', ['token' => $authenticatorCode, 'userId' => $userId]);
    }

    // ---------------------------------------------------------------- paiement

    /** @return string transactionReference Ecobank */
    public function initiatePayment(Payment $payment, string $userId): string
    {
        $r = $this->call('initiatePayment', [
            'userId' => $userId,
            'amount' => Money::decimal($payment->amount_cents, $payment->currency),
            'currency' => $payment->currency,
            'description' => mb_substr($payment->description(), 0, 100),
            'paymentReference' => $payment->id,
        ]);

        return (string) ($r->getTransactionReference() ?? throw new PaymentGatewayException('[ecobank] no transactionReference'));
    }

    public function validatePayment(Payment $payment, string $authenticatorCode): ChargeResult
    {
        $response = $this->send($this->gateway()->validatePayment(['params' => [
            'token' => $authenticatorCode,
            'transactionReference' => $payment->gateway_reference,
        ]]), 'validatePayment');

        /** @var EcobankResponse $response */
        return $response->isSuccessful()
            ? new ChargeResult(PaymentStatus::Paid, $payment->gateway_reference, raw: (array) $response->getData())
            : ChargeResult::failed('ecobank_'.($response->getCode() ?? 'declined'), $response->getMessage() ?? 'Paiement refusé par Ecobank', $payment->gateway_reference, (array) $response->getData());
    }

    private function call(string $operation, array $params): EcobankResponse
    {
        /** @var EcobankResponse $response */
        $response = $this->send($this->gateway()->{$operation}(['params' => $params]), $operation);

        if (! $response->isSuccessful()) {
            throw new PaymentGatewayException(
                '[ecobank] '.$operation.' rejected: '.($response->getMessage() ?? 'unknown error'),
                $response->getCode(),
                (array) $response->getData(),
            );
        }

        return $response;
    }
}
