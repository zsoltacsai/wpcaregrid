<?php

use App\Http\Controllers\Api\V1\AdminAccessController;
use App\Http\Controllers\Api\V1\BackupController;
use App\Http\Controllers\Api\V1\ConnectorController;
use Illuminate\Support\Facades\Route;

/*
| Connector API, version 1: /api/v1/connector/...
| Outbound-only from WordPress; the server never calls a website.
*/
Route::prefix('v1/connector')->name('api.v1.connector.')->controller(ConnectorController::class)->group(function () {
    Route::post('/pair', 'pair')->middleware('throttle:connector-pair')->name('pair');

    Route::middleware(['connector.auth', 'throttle:connector-api'])->group(function () {
        Route::post('/heartbeat', 'heartbeat')->name('heartbeat');
        Route::post('/inventory', 'inventory')->name('inventory');
        Route::get('/jobs', 'jobs')->name('jobs');
        Route::post('/jobs/{job}/result', 'result')->name('jobs.result');
        Route::post('/disconnect', 'disconnect')->name('disconnect');
    });
});

/*
| Backup data: chunked, resumable uploads from the connector, and ranged
| downloads for a restore in progress. Separate limiter: many small requests.
*/
Route::prefix('v1/connector/backups/{backup}')->name('api.v1.connector.backups.')->controller(BackupController::class)
    ->middleware(['connector.auth', 'throttle:connector-transfer'])
    ->whereIn('name', ['files', 'database'])
    ->group(function () {
        Route::get('/objects/{name}', 'status')->name('status');
        Route::put('/objects/{name}', 'chunk')->name('chunk');
        Route::post('/objects/{name}/complete', 'complete')->name('complete');
        Route::get('/objects/{name}/content', 'content')->name('content');
        Route::post('/finalize', 'finalize')->name('finalize');
    });

/*
| Admin access: the connector exchanges the browser's one-time token while
| the customer waits on the site's login page. Tight per-connector limits.
*/
Route::prefix('v1/connector/admin-access')->name('api.v1.connector.admin-access.')->controller(AdminAccessController::class)
    ->middleware(['connector.auth', 'throttle:connector-admin-access'])
    ->group(function () {
        Route::post('/claim', 'claim')->name('claim');
        Route::post('/{uuid}/ended', 'ended')->name('ended');
    });
