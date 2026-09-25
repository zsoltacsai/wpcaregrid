<?php

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/pricing', PricingController::class)->name('pricing');

Route::controller(CheckoutController::class)->prefix('checkout')->name('checkout.')->group(function () {
    // The query string carries only the logical selection (product, plan, interval).
    Route::get('/', 'show')->name('show');
    Route::post('/', 'store')->middleware('throttle:checkout')->name('store');
    Route::get('/success', 'success')->name('success');
    Route::get('/cancel/{intent}', 'cancel')->name('cancel');
});

// Signed by Stripe, so it is exempt from CSRF (see bootstrap/app.php).
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
