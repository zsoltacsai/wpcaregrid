<?php

use App\Http\Controllers\Account\AdminAccessController;
use App\Http\Controllers\Account\BackupController;
use App\Http\Controllers\Account\BillingController;
use App\Http\Controllers\Account\ConnectorDownloadController;
use App\Http\Controllers\Account\DashboardController;
use App\Http\Controllers\Account\OfferController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SubscriptionController;
use App\Http\Controllers\Account\VatVerificationController as AccountVatVerificationController;
use App\Http\Controllers\Account\WebsiteController;
use App\Http\Controllers\Auth\ActivationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\StaffActivationController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\CurrencyController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegacyMarketingRedirectController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PricingController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', [LegacyMarketingRedirectController::class, 'home'])->name('legacy.home');
Route::get('/pricing', [LegacyMarketingRedirectController::class, 'pricing'])->name('legacy.pricing');

Route::get('/robots.txt', RobotsController::class)->name('robots');
Route::get('/sitemap.xml', SitemapController::class)->name('sitemap');

Route::prefix('{locale}')->where(['locale' => 'en|hu'])->group(function () {
    Route::get('/', HomeController::class)->defaults('locale', 'en')->name('home');
    Route::get('/pricing', PricingController::class)->defaults('locale', 'en')->name('pricing');
});

// Display language and display currency. Presentation only; checkout resolves the price on the server.
Route::post('/locale', LocaleController::class)->middleware('throttle:60,1')->name('locale.update');
Route::post('/currency', CurrencyController::class)->middleware('throttle:60,1')->name('currency.update');

Route::controller(CheckoutController::class)->prefix('checkout')->name('checkout.')->group(function () {
    // The query string carries only the logical selection (product, plan, interval).
    Route::get('/', 'show')->name('show');
    Route::post('/promotion-preview', 'preview')->middleware('throttle:checkout-preview')->name('promotion-preview');
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

// Invited operators and owners set their own password; they then sign in on their own login page.
Route::get('/staff/activate/{token}', [StaffActivationController::class, 'show'])->name('staff.activate');
Route::post('/staff/activate', [StaffActivationController::class, 'store'])->middleware('throttle:login')->name('staff.activate.store');

// Customer area. Every model is loaded by UUID and authorised by a policy.
Route::middleware(['auth', 'customer'])->prefix('account')->name('account.')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/websites', [WebsiteController::class, 'index'])->name('websites.index');
    Route::get('/websites/new', [WebsiteController::class, 'create'])->name('websites.create');
    Route::post('/websites', [WebsiteController::class, 'store'])->middleware('throttle:account')->name('websites.store');
    Route::get('/websites/{website}', [WebsiteController::class, 'show'])->name('websites.show');
    Route::post('/websites/{website}/pairing-code', [WebsiteController::class, 'pairingCode'])->middleware('throttle:10,1')->name('websites.pairing-code');
    Route::post('/websites/{website}/disconnect', [WebsiteController::class, 'disconnect'])->middleware('throttle:account')->name('websites.disconnect');
    Route::post('/websites/{website}/admin-access', [AdminAccessController::class, 'maintenance'])->middleware('throttle:admin-access')->name('websites.admin-access.maintenance');
    Route::get('/websites/{website}/admin-access/full-admin', [AdminAccessController::class, 'confirmFullAdmin'])->name('websites.admin-access.full-admin');
    Route::post('/websites/{website}/admin-access/full-admin', [AdminAccessController::class, 'fullAdmin'])->middleware(['throttle:admin-access', 'throttle:admin-access-full'])->name('websites.admin-access.full-admin.store');
    Route::post('/websites/{website}/admin-access/{adminAccessRequest}/revoke', [AdminAccessController::class, 'revoke'])->scopeBindings()->middleware('throttle:20,1')->name('websites.admin-access.revoke');
    Route::get('/websites/{website}/backups', [BackupController::class, 'index'])->name('websites.backups.index');
    Route::post('/websites/{website}/backups', [BackupController::class, 'store'])->middleware('throttle:6,1')->name('websites.backups.store');
    Route::get('/websites/{website}/backups/{backup}/restore', [BackupController::class, 'restoreForm'])->scopeBindings()->name('websites.backups.restore');
    Route::post('/websites/{website}/backups/{backup}/restore', [BackupController::class, 'restore'])->scopeBindings()->middleware('throttle:6,1')->name('websites.backups.restore.store');
    Route::get('/connector/download', ConnectorDownloadController::class)->name('connector.download');

    Route::get('/subscription', [SubscriptionController::class, 'index'])->name('subscription');
    Route::get('/subscriptions/{subscription}', [SubscriptionController::class, 'show'])->name('subscriptions.show');
    Route::post('/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->middleware('throttle:account')->name('subscriptions.cancel');
    Route::post('/subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivate'])->middleware('throttle:account')->name('subscriptions.reactivate');
    Route::post('/subscriptions/{subscription}/change', [SubscriptionController::class, 'change'])->middleware('throttle:account')->name('subscriptions.change');
    Route::post('/billing/payment-method', [SubscriptionController::class, 'paymentMethodPortal'])->middleware('throttle:account')->name('billing.payment-method');

    Route::get('/billing', BillingController::class)->name('billing');

    // Custom recovery offers addressed to this customer (policy: own offers, never drafts).
    Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
    Route::get('/offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
    Route::post('/offers/{offer}/accept', [OfferController::class, 'accept'])->middleware('throttle:account')->name('offers.accept');
    Route::post('/offers/{offer}/pay', [OfferController::class, 'pay'])->middleware('throttle:account')->name('offers.pay');
    Route::post('/offers/{offer}/decline', [OfferController::class, 'decline'])->middleware('throttle:account')->name('offers.decline');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
    Route::put('/profile', [ProfileController::class, 'update'])->middleware('throttle:account')->name('profile.update');
    Route::post('/profile/vat-verification', [AccountVatVerificationController::class, 'store'])->middleware('throttle:vies-verification')->name('profile.vat-verification');
});
