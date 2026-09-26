<?php

use App\Http\Controllers\Operator\ActivityController;
use App\Http\Controllers\Operator\AdminAccessController;
use App\Http\Controllers\Operator\BackupController;
use App\Http\Controllers\Operator\DashboardController;
use App\Http\Controllers\Operator\LoginController;
use App\Http\Controllers\Operator\SecurityController;
use App\Http\Controllers\Operator\UpdateController;
use App\Http\Controllers\Operator\WebsiteController;
use Illuminate\Support\Facades\Route;

/*
| Internal operator console (docs/OPERATOR_CONSOLE.md). Its own guard and
| login; customers are never authenticated here. Every route below the login
| needs the operator role (middleware "operator") and names the explicit
| permission it requires (can:operator.*); a test checks that none is missing.
*/
Route::prefix('operator')->name('operator.')->group(function () {
    Route::middleware('guest:operator')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('login');
        Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
    });

    Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth:operator')->name('logout');

    Route::middleware(['auth:operator', 'operator'])->group(function () {
        Route::get('/', DashboardController::class)->middleware('can:operator.websites.view')->name('dashboard');

        Route::get('/websites', [WebsiteController::class, 'index'])->middleware('can:operator.websites.view')->name('websites.index');
        Route::get('/websites/{website}', [WebsiteController::class, 'show'])->middleware('can:operator.websites.view')->name('websites.show');
        Route::post('/websites/{website}/pairing-code', [WebsiteController::class, 'pairingCode'])->middleware(['can:operator.websites.manage', 'throttle:operator-connector'])->name('websites.pairing-code');
        Route::post('/websites/{website}/disconnect', [WebsiteController::class, 'disconnect'])->middleware(['can:operator.websites.manage', 'throttle:operator-connector'])->name('websites.disconnect');

        Route::post('/websites/{website}/updates', [UpdateController::class, 'store'])->middleware(['can:operator.updates.run', 'throttle:operator-update'])->name('websites.updates.store');

        Route::post('/websites/{website}/backups', [BackupController::class, 'store'])->middleware(['can:operator.backups.run', 'throttle:operator-backup'])->name('websites.backups.store');
        Route::get('/websites/{website}/backups/{backup}/restore', [BackupController::class, 'restoreForm'])->scopeBindings()->middleware('can:operator.backups.restore')->name('websites.backups.restore');
        Route::post('/websites/{website}/backups/{backup}/restore', [BackupController::class, 'restore'])->scopeBindings()->middleware(['can:operator.backups.restore', 'throttle:operator-restore'])->name('websites.backups.restore.store');

        Route::post('/websites/{website}/admin-access', [AdminAccessController::class, 'maintenance'])->middleware(['can:operator.admin_access.request', 'throttle:admin-access'])->name('websites.admin-access.maintenance');
        Route::get('/websites/{website}/admin-access/full-admin', [AdminAccessController::class, 'confirmFullAdmin'])->middleware('can:operator.admin_access.full')->name('websites.admin-access.full-admin');
        Route::post('/websites/{website}/admin-access/full-admin', [AdminAccessController::class, 'fullAdmin'])->middleware(['can:operator.admin_access.full', 'throttle:admin-access', 'throttle:admin-access-full'])->name('websites.admin-access.full-admin.store');
        Route::post('/websites/{website}/admin-access/{adminAccessRequest}/revoke', [AdminAccessController::class, 'revoke'])->scopeBindings()->middleware(['can:operator.admin_access.request', 'throttle:operator-action'])->name('websites.admin-access.revoke');

        Route::get('/backups', [BackupController::class, 'index'])->middleware('can:operator.backups.view')->name('backups.index');
        Route::get('/security', SecurityController::class)->middleware('can:operator.security.view')->name('security.index');
        Route::get('/activity', ActivityController::class)->middleware('can:operator.audit.view')->name('activity.index');
    });
});
