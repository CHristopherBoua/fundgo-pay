<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\GatewayCustomer;
use App\Models\Payment;
use App\Payments\Data\ChargeResult;
use App\Payments\Drivers\EcobankDriver;
use App\Payments\Exceptions\PaymentGatewayException;
use App\Payments\GatewayManager;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Parcours Ecobank Direct Debit :
 *   1. (une fois) lier le compte : OTP e-mail/SMS -> profil -> code Ecobank Authenticator ;
 *   2. (à chaque paiement) demande de débit -> code Ecobank Authenticator -> validation.
 */
class EcobankController extends Controller
{
    public function __construct(
        private readonly GatewayManager $gateways,
        private readonly PaymentProcessor $processor,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request, string $payment): View|RedirectResponse
    {
        [$payment, $driver] = $this->resolve($request, $payment);

        if (! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Processing], true)) {
            return redirect()->route('checkout.return', $payment->id);
        }

        $profile = $this->profile($request, $driver);

        return view('ecobank.pay', [
            'payment' => $payment,
            'linked' => ! empty($profile->meta['linked_at']),
            'linkStep' => $request->session()->get($this->linkKey($request))['step'] ?? 'start',
            'initiated' => $payment->status === PaymentStatus::Processing,
        ]);
    }

    public function sendOtp(Request $request, string $payment): RedirectResponse
    {
        [$payment, $driver] = $this->resolve($request, $payment);
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'account_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{6,20}$/'],
        ]);
        $profile = $this->profile($request, $driver);

        return $this->attempt(function () use ($request, $driver, $profile, $data) {
            $trackingId = $driver->sendOtp($profile->reference, array_filter([
                'email' => $request->user()->email,
                'phoneNumber' => isset($data['phone']) ? preg_replace('/\s+/', '', $data['phone']) : null,
                'accountNumber' => $data['account_number'] ?? null,
            ]));
            $request->session()->put($this->linkKey($request), ['step' => 'otp_sent', 'trackingId' => $trackingId]);
        }, 'Un code vous a été envoyé par e-mail / SMS.');
    }

    public function verifyOtp(Request $request, string $payment): RedirectResponse
    {
        [$payment, $driver] = $this->resolve($request, $payment);
        $code = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{4,8}$/']])['code'];
        $state = $request->session()->get($this->linkKey($request));
        abort_unless(($state['step'] ?? null) === 'otp_sent', 409);
        $profile = $this->profile($request, $driver);

        return $this->attempt(function () use ($request, $driver, $profile, $code, $state) {
            $driver->validateOtp($code, $state['trackingId']);
            $profileId = $driver->createProfile($profile->reference);
            $profile->update(['meta' => array_merge($profile->meta ?? [], ['profile_id' => $profileId])]);
            $request->session()->put($this->linkKey($request), ['step' => 'profile_created']);
        }, 'Installez l\'application Ecobank Authenticator et saisissez le code qu\'elle affiche.');
    }

    public function completeProfile(Request $request, string $payment): RedirectResponse
    {
        [$payment, $driver] = $this->resolve($request, $payment);
        $code = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9A-Za-z]{4,12}$/']])['code'];
        abort_unless(($request->session()->get($this->linkKey($request))['step'] ?? null) === 'profile_created', 409);
        $profile = $this->profile($request, $driver);

        return $this->attempt(function () use ($request, $driver, $profile, $code) {
            $driver->completeProfile($profile->reference, $code);
            $profile->update(['status' => 'active', 'meta' => array_merge($profile->meta ?? [], ['linked_at' => now()->toIso8601String()])]);
            $request->session()->forget($this->linkKey($request));
            $this->audit->log('ecobank.profile_linked', $profile);
        }, 'Votre compte Ecobank est lié. Vous pouvez valider votre don.');
    }

    public function initiate(Request $request, string $payment): RedirectResponse
    {
        [$payment, $driver] = $this->resolve($request, $payment);
        $profile = $this->profile($request, $driver);
        abort_if(empty($profile->meta['linked_at']), 409, 'Compte Ecobank non lié.');
        abort_unless($payment->status === PaymentStatus::Pending, 409);

        return $this->attempt(function () use ($driver, $payment, $profile) {
            $reference = $driver->initiatePayment($payment, $profile->reference);
            $this->processor->apply($payment, new ChargeResult(PaymentStatus::Processing, $reference), actor: 'ecobank');
        }, 'Demande envoyée : ouvrez Ecobank Authenticator pour obtenir votre code.');
    }

    public function confirm(Request $request, string $payment): RedirectResponse
    {
        [$payment, $driver] = $this->resolve($request, $payment);
        $code = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9A-Za-z]{4,12}$/']])['code'];
        abort_unless($payment->status === PaymentStatus::Processing, 409);

        try {
            $result = $driver->validatePayment($payment, $code);
        } catch (PaymentGatewayException $e) {
            Log::warning('[ecobank] validation error', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['code' => 'Validation impossible pour le moment, réessayez.']);
        }

        $this->processor->apply($payment, $result, actor: 'ecobank');

        return redirect()->route('checkout.return', $payment->id);
    }

    /** @return array{0: Payment, 1: EcobankDriver} */
    private function resolve(Request $request, string $paymentId): array
    {
        $payment = $request->user()->payments()->findOrFail($paymentId);
        $driver = $this->gateways->driver($payment->gateway);
        abort_unless($driver instanceof EcobankDriver, 404);

        return [$payment, $driver];
    }

    private function profile(Request $request, EcobankDriver $driver): GatewayCustomer
    {
        return GatewayCustomer::firstOrCreate(
            ['user_id' => $request->user()->id, 'gateway' => $driver->name()],
            ['reference' => $driver->createCustomer($request->user()), 'status' => 'pending'],
        );
    }

    private function linkKey(Request $request): string
    {
        return 'ecobank.link.'.$request->user()->id;
    }

    private function attempt(callable $step, string $success): RedirectResponse
    {
        try {
            $step();
        } catch (PaymentGatewayException $e) {
            Log::warning('[ecobank] step failed', ['error' => $e->getMessage(), 'code' => $e->gatewayCode]);

            return back()->withErrors(['ecobank' => 'Ecobank a refusé l\'opération. Vérifiez vos informations ou réessayez.']);
        }

        return back()->with('status', $success);
    }
}
