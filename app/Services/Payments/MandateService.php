<?php

namespace App\Services\Payments;

use App\Enums\PaymentMethodStatus;
use App\Enums\PaymentMethodType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Payments\Data\SepaMandateRequest;
use App\Payments\GatewayManager;
use App\Services\AuditLogger;
use App\Support\Iban;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Signature électronique d'un mandat SEPA.
 * Conservé : RUM, ICS, texte accepté (version), IP, user-agent, horodatage, preuve PSP.
 * Jamais conservé : l'IBAN complet.
 */
class MandateService
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly CustomerService $customers,
        private readonly AuditLogger $audit,
    ) {}

    public function createSepaMandate(User $user, string $holderName, #[\SensitiveParameter] string $iban, ?string $bic, string $ip, string $userAgent): PaymentMethod
    {
        $iban = Iban::normalize($iban);
        if (! Iban::isValid($iban)) {
            throw new \InvalidArgumentException('IBAN invalide');
        }

        $driver = $this->gateways->forMethod(PaymentMethodType::SepaDebit);
        $fingerprint = Iban::fingerprint($iban);

        // Même IBAN déjà mandaté chez ce PSP : on réutilise le mandat existant.
        $existing = $user->paymentMethods()->active()
            ->where(['gateway' => $driver->name(), 'type' => PaymentMethodType::SepaDebit, 'iban_fingerprint' => $fingerprint])
            ->first();
        if ($existing) {
            return $existing;
        }

        $customerRef = $this->customers->referenceFor($user, $driver);
        $rum = 'FGP-'.Str::upper((string) Str::ulid());
        $signedAt = CarbonImmutable::now();

        $result = $driver->createSepaMandate($user, $customerRef, new SepaMandateRequest(
            holderName: $holderName,
            iban: $iban,
            bic: $bic ? strtoupper($bic) : null,
            rum: $rum,
            signedAt: $signedAt,
            ip: $ip,
            userAgent: $userAgent,
            email: $user->email,
        ));

        $method = PaymentMethod::create([
            'user_id' => $user->id,
            'gateway' => $driver->name(),
            'type' => PaymentMethodType::SepaDebit,
            'status' => $result->status,
            'gateway_customer_ref' => $customerRef,
            'gateway_mandate_ref' => $result->mandateRef,
            'gateway_token' => $result->token,
            'rum' => $rum,
            'holder_name' => $holderName,
            'iban_last4' => Iban::last4($iban),
            'iban_country' => Iban::country($iban),
            'iban_fingerprint' => $fingerprint,
            'bic' => $bic ? strtoupper($bic) : null,
            'consent' => [
                'text_version' => config('payments.sepa.mandate_text_version'),
                'creditor_id' => config('payments.sepa.creditor_id'),
                'creditor_name' => config('payments.sepa.creditor_name'),
                'scheme' => 'CORE',
                'type' => 'RCUR',
                'accepted_at' => $signedAt->toIso8601String(),
                'ip' => $ip,
                'user_agent' => mb_substr($userAgent, 0, 500),
                'email' => $user->email,
            ],
            'proof' => $result->proof,
            'signed_at' => $signedAt,
        ]);

        $this->audit->log('mandate.signed', $method, after: [
            'rum' => $rum, 'gateway' => $driver->name(), 'status' => $result->status->value, 'iban' => '****'.Iban::last4($iban),
        ]);

        if ($result->status === PaymentMethodStatus::Failed) {
            throw new \RuntimeException('Le mandat a été refusé par le prestataire de paiement.');
        }

        return $method;
    }

    public function revoke(PaymentMethod $method): void
    {
        $this->gateways->driver($method->gateway)->revokeMandate($method);

        $method->update(['status' => PaymentMethodStatus::Revoked, 'revoked_at' => now()]);
        $this->audit->log('mandate.revoked', $method);
    }
}
