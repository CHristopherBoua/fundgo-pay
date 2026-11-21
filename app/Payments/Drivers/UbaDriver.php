<?php

namespace App\Payments\Drivers;

use App\Enums\PaymentMethodType;
use App\Enums\PaymentStatus;
use App\Enums\SequenceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Payments\Contracts\ParsesWebhookBatches;
use App\Payments\Contracts\TestsConnection;
use App\Payments\Data\ChargeResult;
use App\Payments\Data\CheckoutSession;
use App\Payments\Data\MandateResult;
use App\Payments\Data\SavedMethod;
use App\Payments\Data\SepaMandateRequest;
use App\Payments\Data\WebhookEvent;
use App\Payments\Exceptions\InvalidWebhookSignature;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\Omnipay\Seerbit\Message\SeerbitResponse;
use App\Payments\Omnipay\Seerbit\SeerbitGateway;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * UBA via SeerBit (Standard Checkout hébergé : carte, virement, USSD selon le pays).
 *
 * - Don ponctuel : lien de paiement hébergé (aucune donnée carte chez nous).
 * - Don régulier :
 *     • `hosted_tokenization` = true  -> 1er paiement hébergé avec tokenize, puis débits
 *       automatiques via /recurring/charge (authorizationCode). À CONFIRMER avec SeerBit :
 *       l'API UBA documentée ne tokenise que via /recurrent/plan/create, qui exige le PAN
 *       côté serveur (PCI DSS) — volontairement NON utilisé ici ;
 *     • sinon -> échéances « à régler par lien ».
 * - Webhooks SeerBit NON signés : chaque événement est relu par l'API de statut.
 */
class UbaDriver extends AbstractOmnipayDriver implements ParsesWebhookBatches, TestsConnection
{
    /** Demande un nouveau jeton à /encrypt/keys avec les clés configurées. */
    public function testConnection(): string
    {
        Cache::forget('seerbit:token:'.$this->name);
        $this->bearerToken();

        return 'Clés UBA valides : jeton obtenu.';
    }

    /** Codes « en attente » (doc UBA Status Code). */
    private const PENDING_CODES = ['S20', 'SM_P', 'SM_09', 'S0', '08', '02'];

    public function supports(PaymentMethodType $type): bool
    {
        return $type === PaymentMethodType::Card;
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), $this->config['currencies'] ?? ['NGN'], true);
    }

    public function supportsOffSession(PaymentMethodType $type): bool
    {
        return $this->supports($type) && (bool) ($this->config['hosted_tokenization'] ?? false);
    }

    /** @return SeerbitGateway */
    protected function gateway(): SeerbitGateway
    {
        $gateway = parent::gateway();
        $gateway->setBearerToken($this->bearerToken());

        return $gateway;
    }

    /** Jeton mis en cache (durée configurable) : un appel /encrypt/keys par période, pas par paiement. */
    private function bearerToken(): string
    {
        return Cache::remember('seerbit:token:'.$this->name, (int) ($this->config['token_ttl'] ?? 1800), function () {
            $gateway = parent::gateway();
            $response = $this->send($gateway->encryptKeys(), 'encryptKeys');
            $token = $response->getData()['data']['EncryptedSecKey']['encryptedKey'] ?? null;

            if (! $response->isSuccessful() || ! $token) {
                throw new PaymentGatewayException('[uba] authentication failed: '.($response->getMessage() ?? 'no token'), (string) $response->getCode());
            }

            return $token;
        });
    }

    public function createCustomer(User $user): string
    {
        return 'uba:'.$user->id; // SeerBit n'a pas d'API client : l'e-mail identifie le payeur
    }

    public function createSepaMandate(User $user, string $customerRef, SepaMandateRequest $request): MandateResult
    {
        throw new PaymentGatewayException('[uba] SEPA is not supported');
    }

    public function startHostedCheckout(Payment $payment, string $customerRef, string $returnUrl): CheckoutSession
    {
        $response = $this->send($this->gateway()->purchase(['params' => [
            'amount' => Money::decimal($payment->amount_cents, $payment->currency),
            'currency' => $payment->currency,
            'country' => $this->country($payment),
            'fullName' => $payment->user->name,
            'paymentReference' => $this->reference($payment),
            'email' => $payment->user->email,
            'productId' => $payment->campaign_id ?? 'don',
            'productDescription' => mb_substr($payment->description(), 0, 100),
            'callbackUrl' => $returnUrl,
            'tokenize' => $payment->sequence_type === SequenceType::First && $this->supportsOffSession(PaymentMethodType::Card),
        ]]), 'purchase');

        if (! $response->isSuccessful() || ! $response->getRedirectUrl()) {
            throw new PaymentGatewayException('[uba] checkout rejected: '.($response->getMessage() ?? 'no redirect link'), (string) $response->getCode(), (array) $response->getData());
        }

        return new CheckoutSession($response->getRedirectUrl(), $this->reference($payment), (array) $response->getData());
    }

    public function chargeOffSession(Payment $payment, PaymentMethod $method): ChargeResult
    {
        if (! $this->supportsOffSession(PaymentMethodType::Card) || ! $method->gateway_token) {
            return ChargeResult::failed('mandate_invalid', 'Débit automatique indisponible : tokenisation UBA non activée');
        }

        /** @var SeerbitResponse $response */
        $response = $this->send($this->gateway()->recurringCharge(['params' => [
            'amount' => Money::decimal($payment->amount_cents, $payment->currency),
            'email' => $payment->user->email,
            'authorizationCode' => $method->gateway_token,
            'paymentReference' => $this->reference($payment),
            'currency' => $payment->currency,
        ]]), 'recurringCharge');

        $data = (array) $response->getData();
        $payments = (array) ($data['data']['payments'] ?? []);
        $code = (string) ($payments['code'] ?? $response->getCode() ?? 'S7');

        return match (true) {
            $response->isSuccessful() && $code === '00' => new ChargeResult(PaymentStatus::Paid, $this->reference($payment), raw: $data),
            in_array($code, self::PENDING_CODES, true) => new ChargeResult(PaymentStatus::Processing, $this->reference($payment), raw: $data),
            default => ChargeResult::failed($code, (string) ($payments['message'] ?? $response->getMessage() ?? 'Paiement refusé'), $this->reference($payment), $data),
        };
    }

    public function fetchPayment(Payment $payment): ChargeResult
    {
        return $this->mapQuery($this->query($this->reference($payment)));
    }

    public function refund(Payment $payment, ?int $amountCents = null): ChargeResult
    {
        // L'API de remboursement n'est pas documentée côté UBA : remboursement depuis le tableau de bord marchand.
        throw new PaymentGatewayException('[uba] refunds must be issued from the UBA merchant dashboard');
    }

    public function revokeMandate(PaymentMethod $method): void
    {
        Log::info('[uba] card token forgotten locally (no revocation API documented)', ['method' => $method->id]);
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        $events = $this->parseWebhookBatch($request);

        return $events[0] ?? throw new InvalidWebhookSignature('Empty SeerBit notification');
    }

    public function parseWebhookBatch(Request $request): array
    {
        $items = $request->json('notificationItems');
        if (! is_array($items) || $items === []) {
            throw new InvalidWebhookSignature('Malformed SeerBit notification');
        }

        $events = [];
        foreach ($items as $item) {
            $n = (array) ($item['notificationRequestItem'] ?? []);
            $type = (string) ($n['eventType'] ?? '');
            $eventId = (string) ($n['eventId'] ?? '');
            $data = (array) ($n['data'] ?? []);
            $reference = $data['reference'] ?? $data['transactionRef'] ?? null;

            if ($eventId === '' || ! preg_match('/^[A-Za-z0-9_\-]{1,64}$/', (string) $reference)) {
                continue;
            }

            // Non signé : seuls les événements de transaction déclenchent une relecture du statut.
            // Remboursements et litiges sont stockés pour revue manuelle (aucun effet automatique).
            $result = str_starts_with($type, 'transaction')
                ? $this->mapQuery($this->query((string) $reference))
                : null;

            $events[] = new WebhookEvent(
                eventId: $eventId,
                type: $type,
                gatewayReference: (string) $reference,
                paymentId: $this->paymentIdFromReference((string) $reference),
                result: $result,
                payload: $n,
            );
        }

        if ($events === []) {
            throw new InvalidWebhookSignature('No usable SeerBit event');
        }

        return $events;
    }

    private function query(string $reference): array
    {
        $response = $this->send($this->gateway()->fetchTransaction(['transactionReference' => $reference]), 'query');

        return (array) $response->getData();
    }

    /** Traduit la réponse de /payments/query en résultat normalisé. */
    public function mapQuery(array $body): ChargeResult
    {
        $data = (array) ($body['data'] ?? []);
        $p = (array) ($data['payments'] ?? []);
        $code = (string) ($data['code'] ?? '');
        $gatewayCode = (string) ($p['gatewayCode'] ?? $code);
        $reference = $p['paymentReference'] ?? null;

        if ($code === 'S404' || $body === []) {
            return new ChargeResult(PaymentStatus::Pending, $reference, raw: $body);
        }

        if ($code === '00' && in_array($gatewayCode, ['00', ''], true)) {
            return new ChargeResult(PaymentStatus::Paid, $reference, savedMethod: $this->savedMethod($p, $data), raw: $body);
        }

        if (in_array($code, self::PENDING_CODES, true) || in_array($gatewayCode, self::PENDING_CODES, true)) {
            return new ChargeResult(PaymentStatus::Processing, $reference, raw: $body);
        }

        return ChargeResult::failed(
            $gatewayCode !== '' ? $gatewayCode : ($code !== '' ? $code : 'S12'),
            trim((string) ($p['reason'] ?? $p['gatewayMessage'] ?? $data['message'] ?? 'Paiement refusé')),
            $reference,
            $body,
        );
    }

    /** Code d'autorisation renvoyé après tokenisation (emplacement à confirmer avec SeerBit). */
    private function savedMethod(array $p, array $data): ?SavedMethod
    {
        $authorizationCode = $p['authorizationCode'] ?? $p['authorization']['authorizationCode'] ?? $data['authorizationCode'] ?? null;
        if (! $authorizationCode || ! $this->supportsOffSession(PaymentMethodType::Card)) {
            return null;
        }

        return new SavedMethod(
            type: PaymentMethodType::Card,
            customerRef: null,
            mandateRef: null,
            token: (string) $authorizationCode,
            cardBrand: $p['channelType'] ?? null,
            cardLast4: $p['lastFourDigits'] ?? null,
            holderName: $data['customers']['customerName'] ?? null,
            proof: ['gateway_ref' => $p['gatewayref'] ?? null, 'type' => $p['type'] ?? null, 'masked_pan' => $p['maskedPan'] ?? null],
        );
    }

    private function country(Payment $payment): string
    {
        return strtoupper($payment->campaign?->country
            ?? ($this->config['country_by_currency'][$payment->currency] ?? null)
            ?? 'NG');
    }

    /** paymentReference SeerBit = id du paiement (unique, une tentative = une référence). */
    private function reference(Payment $payment): string
    {
        return (string) $payment->id;
    }

    private function paymentIdFromReference(string $reference): ?string
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $reference) ? strtolower($reference) : null;
    }
}
