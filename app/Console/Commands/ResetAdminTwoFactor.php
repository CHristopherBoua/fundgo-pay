<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/** Secours : téléphone perdu. Réservé à quelqu'un qui a accès au serveur. */
class ResetAdminTwoFactor extends Command
{
    protected $signature = 'admin:reset-2fa {email}';

    protected $description = 'Réinitialise la double authentification d\'un administrateur (à reconfigurer à la prochaine connexion).';

    public function handle(AuditLogger $audit): int
    {
        $user = User::where('email', strtolower((string) $this->argument('email')))->first();
        if (! $user || ! $user->isAdmin()) {
            $this->error('Aucun administrateur avec cet e-mail.');

            return self::FAILURE;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $audit->log('admin.2fa_reset', $user, actor: 'cli');
        $this->info('Double authentification réinitialisée.');

        return self::SUCCESS;
    }
}
