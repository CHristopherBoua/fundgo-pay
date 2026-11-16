<?php

namespace App\Http\Controllers\Donor;

use App\Http\Controllers\Controller;
use App\Models\RecurringDonation;
use App\Services\Donations\RecurringDonationService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Le donateur pilote lui-même ses dons réguliers (fidélisation : aucune friction pour ajuster). */
class RecurringDonationController extends Controller
{
    public function __construct(private readonly RecurringDonationService $service) {}

    public function pause(Request $request, string $recurring): RedirectResponse
    {
        $data = $request->validate(['until' => ['nullable', 'date', 'after:today']]);
        $this->service->pause($this->find($request, $recurring), isset($data['until']) ? CarbonImmutable::parse($data['until']) : null);

        return back()->with('status', 'Votre don régulier est en pause.');
    }

    public function resume(Request $request, string $recurring): RedirectResponse
    {
        $rd = $this->find($request, $recurring);
        $this->service->resume($rd);

        return back()->with('status', 'Votre don régulier reprend le '.$rd->next_charge_on?->format('d/m/Y').'.');
    }

    public function amount(Request $request, string $recurring): RedirectResponse
    {
        $data = $request->validate(['amount' => ['required', 'regex:/^\d{1,6}([.,]\d{1,2})?$/']]);
        $this->service->changeAmount($this->find($request, $recurring), Money::toCents($data['amount']));

        return back()->with('status', 'Montant mis à jour.');
    }

    public function method(Request $request, string $recurring): RedirectResponse
    {
        $data = $request->validate(['payment_method_id' => ['required', 'string']]);
        $method = $request->user()->paymentMethods()->active()->findOrFail($data['payment_method_id']);
        $this->service->changePaymentMethod($this->find($request, $recurring), $method);

        return back()->with('status', 'Moyen de paiement mis à jour.');
    }

    public function cancel(Request $request, string $recurring): RedirectResponse
    {
        $this->service->cancel($this->find($request, $recurring));

        return back()->with('status', 'Votre don régulier est arrêté. Merci pour votre soutien !');
    }

    /** Résolution limitée aux dons du donateur connecté (pas d'IDOR). */
    private function find(Request $request, string $id): RecurringDonation
    {
        return $request->user()->recurringDonations()->findOrFail($id);
    }
}
