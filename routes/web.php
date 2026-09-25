<?php

use App\Http\Controllers\Account\BillingController;
use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SubscriptionController;
use App\Http\Controllers\Account\WebsiteController;
use App\Http\Controllers\Auth\ActivationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
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

// Authentication. Accounts are created after a confirmed purchase, so there
// is no public registration; activation sets the first password.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:password-email')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:login')->name('password.update');
    Route::get('/account/activate/{token}', [ActivationController::class, 'show'])->name('account.activate');
    Route::post('/account/activate', [ActivationController::class, 'store'])->middleware('throttle:login')->name('account.activate.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Customer area. Every model is loaded by UUID and authorised by a policy.
Route::middleware(['auth', 'customer'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/websites', [WebsiteController::class, 'index'])->name('websites.index');
    Route::get('/websites/new', [WebsiteController::class, 'create'])->name('websites.create');
    Route::post('/websites', [WebsiteController::class, 'store'])->name('websites.store');
    Route::get('/websites/{website}', [WebsiteController::class, 'show'])->name('websites.show');

    Route::get('/subscription', [SubscriptionController::class, 'index'])->name('subscription');
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->name('subscriptions.show');

    Route::get('/billing', BillingController::class)->name('billing');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
});
