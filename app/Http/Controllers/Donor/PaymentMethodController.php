<?php

namespace App\Http\Controllers\Donor;

use App\Enums\RecurringStatus;
use App\Http\Controllers\Controller;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Services\Payments\MandateService;
use App\Support\Iban;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PaymentMethodController extends Controller
{
    public function __construct(private readonly MandateService $mandates) {}

    public function storeSepa(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'holder_name' => ['required', 'string', 'max:70'],
            'iban' => ['required', 'string', function (string $a, mixed $v, Closure $fail) {
                if (! Iban::isValid((string) $v)) {
                    $fail('IBAN invalide.');
                }
            }],
            'bic' => ['nullable', 'regex:/^[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?$/'],
            'mandate_accepted' => ['accepted'],
        ]);

        try {
            $method = $this->mandates->createSepaMandate(
                $request->user(), $data['holder_name'], $data['iban'], $data['bic'] ?? null,
                (string) $request->ip(), (string) $request->userAgent(),
            );
        } catch (PaymentGatewayException|\RuntimeException $e) {
            return back()->withErrors(['iban' => 'Le mandat n\'a pas pu être enregistré.']);
        }

        return back()->with('status', 'Mandat SEPA enregistré (RUM '.$method->rum.').');
    }

    public function destroy(Request $request, string $method): RedirectResponse
    {
        $pm = $request->user()->paymentMethods()->active()->findOrFail($method);

        $inUse = $pm->recurringDonations()
            ->whereIn('status', [RecurringStatus::Active, RecurringStatus::Paused, RecurringStatus::PastDue, RecurringStatus::Pending])
            ->exists();
        if ($inUse) {
            return back()->withErrors(['method' => 'Ce moyen de paiement finance un don régulier : changez-le ou arrêtez le don d\'abord.']);
        }

        try {
            $this->mandates->revoke($pm);
        } catch (PaymentGatewayException) {
            return back()->withErrors(['method' => 'Révocation impossible pour le moment, réessayez plus tard.']);
        }

        return back()->with('status', 'Moyen de paiement supprimé.');
    }
}
