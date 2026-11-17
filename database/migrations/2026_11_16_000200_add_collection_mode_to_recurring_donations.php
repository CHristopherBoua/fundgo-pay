<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deux façons d'encaisser un don régulier :
 * - automatic   : la plateforme débite seule (mandat SEPA, carte tokenisée / MIT) ;
 * - pay_by_link : à chaque échéance le donateur reçoit un lien et paie lui-même
 *                 (Ecobank : OTP obligatoire ; UBA sans tokenisation hébergée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recurring_donations', function (Blueprint $table) {
            $table->string('collection_mode', 16)->default('automatic')->after('payment_method_id');
            $table->string('method_type', 16)->nullable()->after('collection_mode'); // pour pay_by_link
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->after('currency'); // pays de collecte (UBA exige country)
        });
    }

    public function down(): void
    {
        Schema::table('recurring_donations', fn (Blueprint $t) => $t->dropColumn(['collection_mode', 'method_type']));
        Schema::table('campaigns', fn (Blueprint $t) => $t->dropColumn('country'));
    }
};
