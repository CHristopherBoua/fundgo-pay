<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CheckoutReturnController;
use App\Http\Controllers\DonationController;
use App\Http\Controllers\Donor\DashboardController;
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

        Route::post('/recus-fiscaux', [TaxReceiptController::class, 'store'])->middleware('throttle:donate')->name('tax-receipts.store');
        Route::get('/recus-fiscaux/{receipt}', [TaxReceiptController::class, 'download'])->name('tax-receipts.download');
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
