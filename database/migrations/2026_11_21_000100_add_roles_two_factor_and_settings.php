<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Back-office :
 * - rôles donor | admin | superadmin (remplace le booléen is_admin, jamais utilisé) ;
 * - double authentification TOTP obligatoire pour les administrateurs ;
 * - paramètres modifiables depuis l'admin (clés PSP, routage), chiffrés au repos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 16)->default('donor')->after('country');
            $table->text('two_factor_secret')->nullable()->after('remember_token');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            $table->index('role');
        });

        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));

        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // chemin de config, ex. payments.gateways.uba.options.publicKey
            $table->text('value');                    // toujours chiffré (APP_KEY)
            $table->boolean('is_secret')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('country');
        });
        DB::table('users')->whereIn('role', ['admin', 'superadmin'])->update(['is_admin' => true]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'two_factor_secret', 'two_factor_confirmed_at']);
        });
    }
};
