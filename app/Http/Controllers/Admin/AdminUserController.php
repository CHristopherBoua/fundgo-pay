<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Super-admin : gestion des comptes d'administration. */
class AdminUserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.admins', [
            'admins' => User::query()->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPERADMIN])->orderBy('role', 'desc')->orderBy('email')->get(),
        ]);
    }

    /** Promotion d'un compte existant (l'intéressé crée son compte et son mot de passe lui-même). */
    public function grant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_SUPERADMIN])],
        ]);

        $user = User::where('email', strtolower($data['email']))->first();
        if (! $user) {
            return back()->withErrors(['email' => 'Aucun compte avec cet e-mail : la personne doit d\'abord s\'inscrire.']);
        }

        $before = $user->role;
        $user->forceFill(['role' => $data['role']])->save();
        $this->audit->log('admin.role_granted', $user, before: ['role' => $before], after: ['role' => $data['role']]);

        return back()->with('status', $user->email.' est maintenant '.$data['role'].'.');
    }

    public function revoke(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'Vous ne pouvez pas retirer vos propres droits.');
        if ($user->isSuperAdmin() && User::where('role', User::ROLE_SUPERADMIN)->count() <= 1) {
            return back()->withErrors(['admin' => 'Il doit rester au moins un super-administrateur.']);
        }

        $before = $user->role;
        $user->forceFill(['role' => User::ROLE_DONOR, 'two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('admin.role_revoked', $user, before: ['role' => $before], after: ['role' => User::ROLE_DONOR]);

        return back()->with('status', 'Droits retirés à '.$user->email.'.');
    }
}
