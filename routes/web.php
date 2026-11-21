<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\CampaignController as AdminCampaignController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\OperationsController;
use App\Http\Controllers\Admin\PaymentController as AdminPaymentController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CheckoutReturnController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\EcobankController;
use App\Http\Controllers\Donor\DashboardController;
use App\Http\Controllers\Donor\InstallmentController;
use App\Http\Controllers\Donor\PaymentMethodController;
use App\Http\Controllers\Donor\ProfileController;
use App\Http\Controllers\Donor\RecurringDonationController;
use App\Http\Controllers\Donor\TaxReceiptController;
use App\Http\Controllers\FakeGatewayController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CampaignController::class, 'index'])->name('home');
Route::get('/campagnes/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');

Route::middleware('guest')->group(function () {
    Route::get('/inscription', [RegisterController::class, 'create'])->name('register');
    Route::post('/inscription', [RegisterController::class, 'store']);
    Route::get('/connexion', [LoginController::class, 'create'])->name('login');
    Route::post('/connexion', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [LoginController::class, 'destroy'])->name('logout');

    Route::post('/dons', [DonationController::class, 'store'])->middleware('throttle:donate')->name('donations.store');
    Route::get('/paiement/{payment}/retour', CheckoutReturnController::class)->name('checkout.return');

    // Ecobank Direct Debit : liaison du compte (une fois) puis validation OTP de chaque paiement.
    Route::prefix('paiement/{payment}/ecobank')->middleware('throttle:donate')->group(function () {
        Route::get('/', [EcobankController::class, 'show'])->withoutMiddleware('throttle:donate')->name('ecobank.show');
        Route::post('/lier/code', [EcobankController::class, 'sendOtp'])->name('ecobank.link.send');
        Route::post('/lier/verifier', [EcobankController::class, 'verifyOtp'])->name('ecobank.link.verify');
        Route::post('/lier/activer', [EcobankController::class, 'completeProfile'])->name('ecobank.link.complete');
        Route::post('/demande', [EcobankController::class, 'initiate'])->name('ecobank.initiate');
        Route::post('/confirmer', [EcobankController::class, 'confirm'])->name('ecobank.confirm');
    });

    Route::prefix('espace')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::patch('/profil', [ProfileController::class, 'update'])->name('profile.update');

        Route::post('/dons-reguliers/{recurring}/pause', [RecurringDonationController::class, 'pause'])->name('recurring.pause');
        Route::post('/dons-reguliers/{recurring}/reprise', [RecurringDonationController::class, 'resume'])->name('recurring.resume');
        Route::patch('/dons-reguliers/{recurring}/montant', [RecurringDonationController::class, 'amount'])->name('recurring.amount');
        Route::patch('/dons-reguliers/{recurring}/moyen', [RecurringDonationController::class, 'method'])->name('recurring.method');
        Route::post('/dons-reguliers/{recurring}/arret', [RecurringDonationController::class, 'cancel'])->name('recurring.cancel');

        Route::post('/moyens-de-paiement/sepa', [PaymentMethodController::class, 'storeSepa'])->name('payment-methods.sepa');
        Route::delete('/moyens-de-paiement/{method}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');

        Route::get('/echeances/{payment}/payer', [InstallmentController::class, 'pay'])->middleware('throttle:donate')->name('installments.pay');

        Route::post('/recus-fiscaux',[TaxReceiptController::class, 'store'])->middleware('throttle:donate')->name('tax-receipts.store');
        Route::get('/recus-fiscaux/{receipt}', [TaxReceiptController::class, 'download'])->name('tax-receipts.download');
    });
});

/*
|--------------------------------------------------------------------------
| Back-office
|--------------------------------------------------------------------------
| Compte créé par `php artisan admin:create email@exemple.org` (super-admin).
| Double authentification TOTP obligatoire. Les non-admins reçoivent un 404.
*/
Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {
    Route::get('/2fa/configuration', [TwoFactorController::class, 'setup'])->name('2fa.setup');
    Route::post('/2fa/configuration', [TwoFactorController::class, 'confirm'])->middleware('throttle:login')->name('2fa.confirm');
    Route::get('/2fa', [TwoFactorController::class, 'challenge'])->name('2fa.challenge');
    Route::post('/2fa', [TwoFactorController::class, 'verify'])->middleware('throttle:login')->name('2fa.verify');

    Route::middleware('admin')->group(function () {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        Route::get('/campagnes', [AdminCampaignController::class, 'index'])->name('campaigns.index');
        Route::get('/campagnes/nouvelle', [AdminCampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campagnes', [AdminCampaignController::class, 'store'])->name('campaigns.store');
        Route::get('/campagnes/{campaign}', [AdminCampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('/campagnes/{campaign}', [AdminCampaignController::class, 'update'])->name('campaigns.update');

        Route::get('/paiements', [AdminPaymentController::class, 'index'])->name('payments.index');
        Route::post('/paiements/{payment}/rembourser', [AdminPaymentController::class, 'refund'])->name('payments.refund');

        Route::get('/donateurs', [OperationsController::class, 'donors'])->name('donors');
        Route::get('/recus-fiscaux', [OperationsController::class, 'receipts'])->name('receipts');
        Route::post('/recus-fiscaux/emettre', [OperationsController::class, 'issueReceipts'])->name('receipts.issue');
        Route::get('/webhooks', [OperationsController::class, 'webhooks'])->name('webhooks');
        Route::post('/webhooks/{event}/rejouer', [OperationsController::class, 'replay'])->name('webhooks.replay');
        Route::get('/journal', [OperationsController::class, 'audit'])->name('audit');

        // Super-administrateur uniquement
        Route::middleware('admin:super')->group(function () {
            Route::get('/parametres', [SettingsController::class, 'index'])->name('settings');
            Route::put('/parametres/passerelles/{gateway}', [SettingsController::class, 'updateGateway'])->name('settings.gateway');
            Route::put('/parametres/routage', [SettingsController::class, 'updateRouting'])->name('settings.routing');
            Route::post('/parametres/passerelles/{gateway}/tester', [SettingsController::class, 'test'])->middleware('throttle:10,1')->name('settings.test');

            Route::get('/administrateurs', [AdminUserController::class, 'index'])->name('admins');
            Route::post('/administrateurs', [AdminUserController::class, 'grant'])->name('admins.grant');
            Route::delete('/administrateurs/{user}', [AdminUserController::class, 'revoke'])->name('admins.revoke');

            Route::post('/paiements/{payment}/marquer-rembourse', [AdminPaymentController::class, 'markRefunded'])->name('payments.mark-refunded');
            Route::post('/recus-fiscaux/{receipt}/annuler', [OperationsController::class, 'cancelReceipt'])->name('receipts.cancel');
        });
    });
});

Route::post('/webhooks/{gateway}', WebhookController::class)
    ->middleware('throttle:webhooks')
    ->where('gateway', '[a-z0-9_-]+')
    ->name('webhooks.gateway');

// Page de paiement simulée (développement / démo uniquement).
if (config('payments.gateways.fake.enabled')) {
    Route::middleware('auth')->group(function () {
        Route::get('/fake-gateway/checkout/{payment}', [FakeGatewayController::class, 'show'])->name('fake-gateway.checkout');
        Route::post('/fake-gateway/checkout/{payment}', [FakeGatewayController::class, 'complete'])->name('fake-gateway.complete');
    });
}
