<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Profil donateur : l'adresse postale est nécessaire pour émettre un reçu fiscal
 * (cerfa 11580) et pour le mandat SEPA (adresse du débiteur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('email');
            $table->string('address_line1')->nullable()->after('phone');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('postal_code', 16)->nullable()->after('address_line2');
            $table->string('city')->nullable()->after('postal_code');
            $table->char('country', 2)->default('FR')->after('city');
            $table->boolean('is_admin')->default(false)->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'is_admin']);
        });
    }
};
