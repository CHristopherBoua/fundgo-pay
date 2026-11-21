<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accès back-office : rôle admin (ou superadmin) + double authentification validée
 * dans cette session depuis moins de `admin.two_factor_ttl` minutes.
 * Un non-admin reçoit un 404 : l'existence de l'espace n'est pas révélée.
 */
class EnsureAdmin
{
    public const SESSION_KEY = 'admin.2fa_passed';

    public function handle(Request $request, Closure $next, ?string $level = null): Response
    {
        $user = $request->user();

        abort_unless($user?->isAdmin(), 404);
        abort_if($level === 'super' && ! $user->isSuperAdmin(), 403, 'Réservé au super-administrateur.');

        if (! $user->hasTwoFactor()) {
            return redirect()->route('admin.2fa.setup');
        }

        $passed = $request->session()->get(self::SESSION_KEY);
        $fresh = is_array($passed)
            && ($passed['user'] ?? null) === $user->id
            && now()->timestamp - (int) ($passed['at'] ?? 0) < (int) config('admin.two_factor_ttl', 720) * 60;

        if (! $fresh) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('admin.2fa.challenge');
        }

        return $next($request);
    }
}
