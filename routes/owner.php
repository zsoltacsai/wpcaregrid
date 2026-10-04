<?php

use App\Http\Controllers\Owner\ContentController;
use App\Http\Controllers\Owner\CustomerController;
use App\Http\Controllers\Owner\DashboardController;
use App\Http\Controllers\Owner\LoginController;
use App\Http\Controllers\Owner\OperatorController;
use App\Http\Controllers\Owner\PricingController;
use App\Http\Controllers\Owner\SeoController;
use App\Http\Controllers\Owner\SystemController;
use App\Http\Controllers\Owner\WebsiteController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
| Owner administration (docs/OWNER_ADMIN.md). Its own guard and login;
| customers and operators are never authenticated here. Every route below
| the login needs the owner role (middleware "owner") and names the ability
| it requires (can:owner.*); a test checks that none is missing. Changes
| are rate limited and audited (admin_audit_events).
*/

// Only operator logins: a customer or owner ID in the URL is a 404.
Route::bind('operatorUser', fn (string $value) => User::where('role', User::ROLE_OPERATOR)->whereKey(ctype_digit($value) ? (int) $value : 0)->firstOrFail());

Route::prefix('owner')->name('owner.')->group(function () {
    Route::middleware('guest:owner')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    });

    Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth:owner')->name('logout');

    Route::middleware(['auth:owner', 'owner'])->group(function () {
        Route::get('/', DashboardController::class)->middleware('can:owner.dashboard.view')->name('dashboard');

        Route::get('/customers', [CustomerController::class, 'index'])->middleware('can:owner.customers.view')->name('customers.index');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->middleware('can:owner.customers.view')->name('customers.show');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware(['can:owner.customers.manage', 'throttle:owner-sensitive'])->name('customers.update');

        Route::get('/websites', [WebsiteController::class, 'index'])->middleware('can:owner.websites.view')->name('websites.index');
        Route::get('/websites/{website}', [WebsiteController::class, 'show'])->middleware('can:owner.websites.view')->name('websites.show');
        // Through BackupService and SecurityScanService, exactly as in the operator console.
        Route::post('/websites/{website}/backups', [WebsiteController::class, 'backup'])->middleware(['can:owner.websites.operate', 'throttle:owner-external'])->name('websites.backups.store');
        Route::post('/websites/{website}/security-scans', [WebsiteController::class, 'scan'])->middleware(['can:owner.websites.operate', 'throttle:owner-external'])->name('websites.security-scans.store');

        Route::get('/operators', [OperatorController::class, 'index'])->middleware('can:owner.operators.manage')->name('operators.index');
        Route::post('/operators', [OperatorController::class, 'store'])->middleware(['can:owner.operators.manage', 'throttle:staff-invitation'])->name('operators.store');
        Route::get('/operators/{operatorUser}', [OperatorController::class, 'show'])->middleware('can:owner.operators.manage')->name('operators.show');
        Route::put('/operators/{operatorUser}/permissions', [OperatorController::class, 'permissions'])->middleware(['can:owner.operators.manage', 'throttle:owner-sensitive'])->name('operators.permissions');
        Route::post('/operators/{operatorUser}/disable', [OperatorController::class, 'disable'])->middleware(['can:owner.operators.manage', 'throttle:owner-sensitive'])->name('operators.disable');
        Route::post('/operators/{operatorUser}/enable', [OperatorController::class, 'enable'])->middleware(['can:owner.operators.manage', 'throttle:owner-sensitive'])->name('operators.enable');
        Route::post('/operators/{operatorUser}/invitation', [OperatorController::class, 'resendInvitation'])->middleware(['can:owner.operators.manage', 'throttle:staff-invitation'])->name('operators.invitation');

        Route::get('/pricing', [PricingController::class, 'index'])->middleware('can:owner.pricing.manage')->name('pricing.index');
        Route::post('/pricing/verify-stripe', [PricingController::class, 'verifyStripe'])->middleware(['can:owner.pricing.manage', 'throttle:owner-external'])->name('pricing.verify');
        Route::get('/pricing/prices/{price}', [PricingController::class, 'edit'])->middleware('can:owner.pricing.manage')->name('pricing.prices.edit');
        Route::put('/pricing/prices/{price}', [PricingController::class, 'update'])->middleware(['can:owner.pricing.manage', 'throttle:owner-sensitive'])->name('pricing.prices.update');
        Route::post('/pricing/prices/{price}/publish', [PricingController::class, 'publish'])->middleware(['can:owner.pricing.manage', 'throttle:owner-sensitive', 'throttle:owner-external'])->name('pricing.prices.publish');
        Route::post('/pricing/prices/{price}/discard', [PricingController::class, 'discard'])->middleware(['can:owner.pricing.manage', 'throttle:owner-sensitive'])->name('pricing.prices.discard');

        // Homepage, site and plan wording. SEO sections are edited under /owner/seo.
        Route::get('/content', [ContentController::class, 'index'])->middleware('can:owner.content.manage')->name('content.index');
        Route::get('/content/{section}/{locale}', [ContentController::class, 'edit'])->middleware('can:owner.content.manage')->name('content.edit');
        Route::put('/content/{section}/{locale}', [ContentController::class, 'update'])->middleware(['can:owner.content.manage', 'throttle:owner-sensitive'])->name('content.update');
        Route::post('/content/{section}/{locale}/unpublish', [ContentController::class, 'unpublish'])->middleware(['can:owner.content.manage', 'throttle:owner-sensitive'])->name('content.unpublish');
        Route::post('/content/{section}/{locale}/discard', [ContentController::class, 'discard'])->middleware(['can:owner.content.manage', 'throttle:owner-sensitive'])->name('content.discard');
        // Drafts: owner sign-in and a short-lived signed link, never public.
        Route::get('/preview/{locale}', [ContentController::class, 'preview'])->middleware(['can:owner.content.manage', 'signed'])->name('content.preview');

        Route::get('/seo', SeoController::class)->middleware('can:owner.seo.manage')->name('seo.index');
        Route::get('/seo/{section}/{locale}', [ContentController::class, 'edit'])->middleware('can:owner.seo.manage')->name('seo.edit');
        Route::put('/seo/{section}/{locale}', [ContentController::class, 'update'])->middleware(['can:owner.seo.manage', 'throttle:owner-sensitive'])->name('seo.update');
        Route::post('/seo/{section}/{locale}/unpublish', [ContentController::class, 'unpublish'])->middleware(['can:owner.seo.manage', 'throttle:owner-sensitive'])->name('seo.unpublish');
        Route::post('/seo/{section}/{locale}/discard', [ContentController::class, 'discard'])->middleware(['can:owner.seo.manage', 'throttle:owner-sensitive'])->name('seo.discard');

        Route::get('/system', SystemController::class)->middleware('can:owner.system.view')->name('system');
    });
});
