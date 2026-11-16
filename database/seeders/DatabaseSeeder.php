<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Données de démonstration (passerelle « fake », aucun appel PSP).
     * Compte démo : demo@fundgo.test / mot de passe ci-dessous (local uniquement).
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'demo@fundgo.test'],
            ['name' => 'Donateur Démo', 'password' => env('DEMO_PASSWORD', 'demo-fundgo-2026'), 'city' => 'Lyon', 'postal_code' => '69001'],
        );

        Campaign::firstOrCreate(['slug' => 'ecole-numerique'], [
            'title' => 'Une salle informatique pour l\'école du quartier',
            'summary' => 'Équiper 30 postes et former les enseignants.',
            'description' => "Votre soutien finance le matériel, la connexion et 40 heures de formation.\nChaque don régulier nous permet de planifier sereinement.",
            'goal_cents' => 1_500_000,
            'status' => 'open',
            'allows_recurring' => true,
        ]);

        Campaign::firstOrCreate(['slug' => 'fonds-solidarite'], [
            'title' => 'Fonds de solidarité',
            'summary' => 'Aide d\'urgence aux familles accompagnées.',
            'description' => 'Fonds général, affecté selon les besoins validés par le comité.',
            'goal_cents' => null,
            'status' => 'open',
            'allows_recurring' => true,
        ]);

        Campaign::firstOrCreate(['slug' => 'gala-2026'], [
            'title' => 'Gala annuel 2026',
            'summary' => 'Soirée de levée de fonds - dons ponctuels uniquement.',
            'goal_cents' => 500_000,
            'status' => 'open',
            'allows_recurring' => false,
            'tax_deductible' => false, // billet avec contrepartie : pas de reçu fiscal
        ]);
    }
}
