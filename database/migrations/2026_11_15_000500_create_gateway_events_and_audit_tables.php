<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Webhooks PSP : stockés tels quels, traités de façon idempotente, rejouables.
        Schema::create('gateway_events', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->string('event_id');
            $table->string('type', 128);
            $table->string('gateway_reference')->nullable();
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
            $table->index('processed_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at')->useCurrent();
            $table->string('actor');                 // user:12 | system | gateway:mollie
            $table->string('action', 64);
            $table->string('entity_type', 64);
            $table->string('entity_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('gateway_events');
    }
};
