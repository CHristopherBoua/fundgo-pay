<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moyens de paiement réutilisables : mandats SEPA (RUM) et cartes enrôlées (3DS puis MIT).
 * L'IBAN complet et le PAN ne sont JAMAIS stockés : seulement 4 derniers caractères
 * et une empreinte, les références restent chez le PSP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->string('reference');
            $table->timestamps();

            $table->unique(['user_id', 'gateway']);
            $table->unique(['gateway', 'reference']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->string('type', 16);                    // sepa_debit | card
            $table->string('status', 16)->default('pending'); // pending | active | revoked | failed

            $table->string('gateway_customer_ref')->nullable();
            $table->string('gateway_mandate_ref')->nullable();
            $table->string('gateway_token')->nullable();   // id du moyen de paiement chez le PSP (pm_…, cardToken…)

            // SEPA
            $table->string('rum', 35)->nullable()->unique(); // Référence Unique de Mandat
            $table->string('holder_name')->nullable();
            $table->string('iban_last4', 4)->nullable();
            $table->char('iban_country', 2)->nullable();
            $table->char('iban_fingerprint', 64)->nullable();
            $table->string('bic', 11)->nullable();

            // Carte
            $table->string('card_brand', 32)->nullable();
            $table->string('card_last4', 4)->nullable();
            $table->string('card_expiry', 7)->nullable(); // MM/YYYY

            $table->json('consent')->nullable();  // texte accepté, IP, user-agent, horodatage
            $table->json('proof')->nullable();    // réponse PSP figée (3DS, statut mandat…)
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['gateway', 'gateway_mandate_ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('gateway_customers');
    }
};
