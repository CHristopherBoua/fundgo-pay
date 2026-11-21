<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdmin;
use App\Services\AuditLogger;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Première configuration : secret gardé en session tant qu'il n'est pas confirmé. */
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 404);

        if ($user->hasTwoFactor()) {
            return redirect()->route('admin.2fa.challenge');
        }

        $secret = $request->session()->get('admin.2fa_pending') ?? Totp::generateSecret();
        $request->session()->put('admin.2fa_pending', $secret);

        return view('admin.two-factor.setup', [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri' => Totp::uri($secret, $user->email, (string) config('admin.issuer')),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() && ! $user->hasTwoFactor(), 404);
        $code = (string) $request->validate(['code' => 'required|string|max:10'])['code'];
        $secret = (string) $request->session()->get('admin.2fa_pending');

        if ($secret === '' || ! Totp::verify($secret, $code)) {
            return back()->withErrors(['code' => 'Code incorrect. Vérifiez l\'heure de votre téléphone.']);
        }

        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('admin.2fa_pending');
        $this->markPassed($request);
        $this->audit->log('admin.2fa_enabled', $user);

        return redirect()->route('admin.dashboard')->with('status', 'Double authentification activée.');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 404);

        return $user->hasTwoFactor() ? view('admin.two-factor.challenge') : redirect()->route('admin.2fa.setup');
    }

    public function verify(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin() && $user->hasTwoFactor(), 404);
        $code = (string) $request->validate(['code' => 'required|string|max:10'])['code'];

        if (! Totp::verify((string) $user->two_factor_secret, $code)) {
            $this->audit->log('admin.2fa_failed', $user);

            return back()->withErrors(['code' => 'Code incorrect.']);
        }

        $request->session()->regenerate();
        $this->markPassed($request);

        return redirect()->intended(route('admin.dashboard'));
    }

    private function markPassed(Request $request): void
    {
        $request->session()->put(EnsureAdmin::SESSION_KEY, ['user' => $request->user()->id, 'at' => now()->timestamp]);
    }
}
