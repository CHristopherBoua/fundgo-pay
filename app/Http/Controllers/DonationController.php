<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Http\Requests\StoreDonationRequest;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Services\Donations\DonationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class DonationController extends Controller
{
    public function store(StoreDonationRequest $request, DonationService $donations): RedirectResponse
    {
        try {
            $outcome = $donations->donate($request->user(), $request->toDonationRequest());
        } catch (ModelNotFoundException) {
            return back()->withInput($request->safe()->except('iban'))->withErrors(['payment_method_id' => 'Moyen de paiement introuvable.']);
        } catch (PaymentGatewayException $e) {
            Log::warning('[donation] gateway error', ['user' => $request->user()->id, 'error' => $e->getMessage()]);

            return back()->withInput($request->safe()->except('iban'))
                ->withErrors(['method' => 'Le prestataire de paiement a refusé l\'opération. Vérifiez vos informations ou réessayez plus tard.']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->withInput($request->safe()->except('iban'))->withErrors(['method' => $e->getMessage()]);
        }

        if ($outcome->redirectUrl) {
            return redirect()->away($outcome->redirectUrl);
        }

        $status = $outcome->payment?->status;

        return redirect()->route('dashboard')->with('status', match (true) {
            $status === PaymentStatus::Paid => 'Merci ! Votre don a bien été reçu.',
            $status === PaymentStatus::Processing => 'Merci ! Votre prélèvement est en cours de traitement.',
            $status === PaymentStatus::Failed => 'Le paiement a échoué : '.$outcome->payment->failure_message,
            $outcome->recurring !== null => 'Merci ! Votre don régulier est enregistré. Premier prélèvement le '.$outcome->recurring->next_charge_on?->format('d/m/Y').'.',
            default => 'Votre don est enregistré.',
        });
    }
}
