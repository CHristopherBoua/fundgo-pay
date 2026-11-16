<?php

namespace App\Http\Requests;

use App\Enums\Frequency;
use App\Models\Campaign;
use App\Services\Donations\DonationRequest;
use App\Support\Iban;
use App\Support\Money;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $min = config('payments.min_amount_cents');
        $max = config('payments.max_amount_cents');

        return [
            'campaign_id' => ['nullable', 'string', Rule::exists('campaigns', 'id')->where('status', 'open')],
            'amount' => ['required', 'regex:/^\d{1,6}([.,]\d{1,2})?$/', function (string $attr, mixed $value, Closure $fail) use ($min, $max) {
                $cents = Money::toCents((string) $value);
                if ($cents < $min || $cents > $max) {
                    $fail(sprintf('Le montant doit être compris entre %s et %s.', Money::format($min), Money::format($max)));
                }
            }],
            'frequency' => ['required', Rule::in(['once', ...array_column(Frequency::cases(), 'value')])],
            'day_of_month' => ['nullable', 'integer', 'between:1,28'],
            'method' => ['required', Rule::in([DonationRequest::METHOD_SAVED, DonationRequest::METHOD_SEPA, DonationRequest::METHOD_CARD])],
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
            'recurring_consent.accepted' =>'Vous devez autoriser les débits récurrents sur votre carte.',
            'iban.required_if' => 'L\'IBAN est obligatoire pour un prélèvement.',
            'holder_name.required_if' => 'Le titulaire du compte est obligatoire.',
        ];
    }

    public function toDonationRequest(): DonationRequest
    {
        $frequency = $this->input('frequency') === 'once' ? null : Frequency::from($this->input('frequency'));

        return new DonationRequest(
            campaign: $this->filled('campaign_id') ? Campaign::find($this->input('campaign_id')) : null,
            amountCents: Money::toCents((string) $this->input('amount')),
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
        );
    }
}
