<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certains PSP exigent un enrôlement interactif du client avant tout débit
 * (Ecobank Direct Debit : OTP + profil + appli Ecobank Authenticator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_customers', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('reference'); // pending | active | deactivated
            $table->json('meta')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_customers', fn (Blueprint $t) => $t->dropColumn(['status', 'meta']));
    }
};
