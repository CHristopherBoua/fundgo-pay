<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Crée (ou promeut) un administrateur. Le mot de passe est saisi dans le terminal :
 * il n'apparaît jamais dans le code, le seeder ni l'historique du shell.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {email : E-mail du compte}
        {--name= : Nom affiché (nouveau compte)}
        {--role=superadmin : superadmin | admin}';

    protected $description = 'Crée ou promeut un compte administrateur / super-administrateur.';

    public function handle(AuditLogger $audit): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $role = (string) $this->option('role');

        if (! in_array($role, [User::ROLE_ADMIN, User::ROLE_SUPERADMIN], true)) {
            $this->error('Rôle invalide : superadmin ou admin.');

            return self::INVALID;
        }
        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error('E-mail invalide.');

            return self::INVALID;
        }

        $user = User::where('email', $email)->first();

        if (! $user) {
            $password = (string) $this->secret('Mot de passe (12 caractères min.)');
            $confirm = (string) $this->secret('Confirmation');
            $check = Validator::make(['password' => $password, 'password_confirmation' => $confirm], [
                'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            ]);
            if ($check->fails()) {
                $this->error(implode(' ', $check->errors()->all()));

                return self::INVALID;
            }

            $user = User::create([
                'name' => (string) ($this->option('name') ?: strstr($email, '@', true)),
                'email' => $email,
                'password' => $password,
            ]);
        }

        $before = $user->role;
        $user->forceFill(['role' => $role, 'email_verified_at' => $user->email_verified_at ?? now()])->save();
        $audit->log('admin.role_granted', $user, before: ['role' => $before], after: ['role' => $role], actor: 'cli');

        $this->info("{$user->email} est maintenant {$role}.");
        $this->line('Connectez-vous puis ouvrez /admin : la double authentification sera configurée à la première visite.');

        return self::SUCCESS;
    }
}
