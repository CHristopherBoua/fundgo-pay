<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reçus fiscaux (cerfa 11580*05) — un reçu récapitulatif annuel par donateur.
 *
 * - Numérotation continue par année, sans trou (compteur verrouillé).
 * - Le reçu est figé à l'émission : identité du donateur, de l'organisme, montant,
 *   liste des paiements. Le PDF est regénéré à l'identique depuis cet instantané.
 * - Un reçu n'est jamais modifié : en cas de remboursement / rejet, il est annulé
 *   et remplacé par un reçu rectificatif portant un nouveau numéro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            // Un don avec contrepartie (billet de gala…) n'ouvre pas droit à réduction d'impôt.
            $table->boolean('tax_deductible')->default(true)->after('allows_recurring');
        });

        Schema::create('tax_receipt_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('fiscal_year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('tax_receipts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 32)->unique();          // ex. 2026-000042
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 16)->default('issued');   // issued | cancelled
            $table->json('payment_ids');
            $table->json('donor');          // instantané : nom, adresse
            $table->json('organization');   // instantané : dénomination, adresse, objet, qualité, article CGI
            $table->json('payments');       // instantané : date, montant, mode
            $table->char('content_sha256', 64);
            $table->timestamp('issued_at');
            $table->foreignUlid('replaces_id')->nullable()->constrained('tax_receipts')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'fiscal_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_receipts');
        Schema::dropIfExists('tax_receipt_counters');
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('tax_deductible'));
    }
};
