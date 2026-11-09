<?php

use App\Http\Controllers\Api\ConnectorController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/connector')
    ->middleware('throttle:connector')
    ->group(function (): void {
        Route::post('/heartbeat', [ConnectorController::class, 'heartbeat'])
            ->middleware('connector.auth:heartbeat')
            ->name('api.connector.heartbeat');

        Route::post('/events', [ConnectorController::class, 'events'])
            ->middleware('connector.auth:events:write')
            ->name('api.connector.events');

        Route::post('/products', [ConnectorController::class, 'products'])
            ->middleware('connector.auth:products:write')
            ->name('api.connector.products');

        Route::post('/orders', [ConnectorController::class, 'orders'])
            ->middleware('connector.auth:orders:write')
            ->name('api.connector.orders');
    });
