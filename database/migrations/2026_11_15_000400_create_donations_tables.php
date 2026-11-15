<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Don récurrent (« abonnement ») : un échéancier attaché à un moyen de paiement.
        Schema::create('recurring_donations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('frequency', 16);                 // monthly | quarterly | yearly
            $table->unsignedTinyInteger('day_of_month');     // 1..28
            $table->string('status', 16)->default('pending'); // pending | active | paused | past_due | cancelled
            $table->date('next_charge_on')->nullable();
            $table->date('prenotified_for')->nullable();      // échéance pour laquelle la pré-notification SEPA est partie
            $table->date('paused_until')->nullable();
            $table->string('status_reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_charge_on']);
        });

        // Don ponctuel.
        Schema::create('donations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 16)->default('pending'); // pending | completed | failed | cancelled | refunded
            $table->string('message', 500)->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        // Tentative d'encaissement (une ligne par essai, y compris les relances).
        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('donation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('recurring_donation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 32);
            $table->string('method_type', 16);        // sepa_debit | card
            $table->string('sequence_type', 16);      // oneoff | first | recurring
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3)->default('EUR');
            $table->string('status', 16)->default('pending');
            $table->date('period')->nullable();        // échéance couverte (dons récurrents)
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->string('gateway_reference')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_reference']);
            $table->unique(['recurring_donation_id', 'period', 'attempt_no']);
            $table->index(['status', 'next_retry_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('donations');
        Schema::dropIfExists('recurring_donations');
    }
};
