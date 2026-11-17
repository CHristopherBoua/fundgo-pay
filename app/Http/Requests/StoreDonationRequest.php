<?php

namespace App\Http\Requests;

use App\Enums\Frequency;
use App\Enums\PaymentMethodType;
use App\Models\Campaign;
use App\Services\Donations\DonationRequest;
use App\Support\Iban;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDonationRequest extends FormRequest
{
    private ?Campaign $campaignModel = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function currency(): string
    {
        $this->campaignModel ??= $this->filled('campaign_id') ? Campaign::find($this->input('campaign_id')) : null;

        return strtoupper($this->campaignModel?->currency ?? config('payments.currency'));
    }

    /** "5 000" / "5 000" (espaces insécables) -> "5000" avant validation. */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('amount'))) {
            $this->merge(['amount' => str_replace([' ', "\u{00A0}", "\u{202F}"], '', $this->input('amount'))]);
        }
    }

    public function rules(): array
    {
        $currency = $this->currency();
        $decimals = Money::isSupported($currency) ? Money::decimals($currency) : 2;
        $allowedMethods = array_map(fn (PaymentMethodType $t) => match ($t) {
            PaymentMethodType::SepaDebit => DonationRequest::METHOD_SEPA,
            PaymentMethodType::Card => DonationRequest::METHOD_CARD,
            PaymentMethodType::EcobankAccount => DonationRequest::METHOD_ECOBANK,
        }, PaymentMethodType::forCurrency($currency));

        return [
            'campaign_id' => ['nullable', 'string', Rule::exists('campaigns', 'id')->where('status', 'open')],
            'amount' => ['required', $decimals > 0 ? 'regex:/^\d{1,9}([.,]\d{1,'.$decimals.'})?$/' : 'regex:/^\d{1,9}$/',
                function (string $attr, mixed $value, Closure $fail) use ($currency) {
                    if (! Money::isSupported($currency)) {
                        $fail('Devise non prise en charge.');

                        return;
                    }
                    $minor = Money::toMinor((string) $value, $currency);
                    if ($minor < Money::min($currency) || $minor > Money::max($currency)) {
                        $fail(sprintf('Le montant doit être compris entre %s et %s.', Money::format(Money::min($currency), $currency), Money::format(Money::max($currency), $currency)));
                    }
                }],
            'frequency' => ['required', Rule::in(['once', ...array_column(Frequency::cases(), 'value')])],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'method' => ['required', Rule::in([DonationRequest::METHOD_SAVED, ...$allowedMethods])],
            'payment_method_id' => ['required_if:method,saved', 'nullable', 'string'],
            'holder_name' => ['required_if:method,sepa', 'nullable', 'string', 'max:70'],
            'iban' => ['required_if:method,sepa', 'nullable', 'string', function (string $attr, mixed $value, Closure $fail) {
                if ($value !== null && ! Iban::isValid((string) $value)) {
                    $fail('IBAN invalide.');
                }
            }],
            'bic' => ['nullable', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'mandate_accepted' => ['accepted_if:method,sepa'],
            'recurring_consent' => Rule::when(
                fn () => $this->input('method') === DonationRequest::METHOD_CARD && $this->input('frequency') !== 'once',
                ['accepted'],
            ),
            'message' => ['nullable', 'string', 'max:500'],
            'anonymous' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'mandate_accepted.accepted_if' => 'Vous devez accepter le mandat de prélèvement SEPA.',
            'recurring_consent.accepted' => 'Vous devez autoriser les débits récurrents sur votre carte.',
            'iban.required_if' => 'L\'IBAN est obligatoire pour un prélèvement.',
            'holder_name.required_if' => 'Le titulaire du compte est obligatoire.',
            'method.in' => 'Ce moyen de paiement n\'est pas disponible pour cette devise.',
        ];
    }

    public function toDonationRequest(): DonationRequest
    {
        $frequency = $this->input('frequency') === 'once' ? null : Frequency::from($this->input('frequency'));
        $currency = $this->currency();

        return new DonationRequest(
            campaign: $this->campaignModel,
            amountCents: Money::toMinor((string) $this->input('amount'), $currency),
            frequency: $frequency,
            method: $this->input('method'),
            paymentMethodId: $this->input('payment_method_id'),
            holderName: $this->input('holder_name'),
            iban: $this->input('iban'),
            bic: $this->input('bic'),
            dayOfMonth: (int) ($this->input('day_of_month') ?: min(28, (int) now()->format('j'))),
            message: $this->input('message'),
            anonymous: $this->boolean('anonymous'),
            ip: (string) $this->ip(),
            userAgent: (string) $this->userAgent(),
            currency: $currency,
        );
    }
}
