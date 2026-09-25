<?php

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
