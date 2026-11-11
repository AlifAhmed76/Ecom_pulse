<?php

use App\Http\Controllers\Api\ConnectorController;
use App\Http\Controllers\Api\WidgetController;
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

Route::prefix('v1/widget')
    ->group(function (): void {
        Route::post('/{publicKey}/token', [WidgetController::class, 'token'])
            ->middleware('throttle:widget')
            ->name('api.widget.token');

        Route::post('/ask', [WidgetController::class, 'ask'])
            ->middleware('throttle:widget')
            ->name('api.widget.ask');

        Route::post('/order-status', [WidgetController::class, 'orderStatus'])
            ->middleware('throttle:widget-order-lookup')
            ->name('api.widget.order-status');

        Route::post('/lead', [WidgetController::class, 'lead'])
            ->middleware('throttle:widget')
            ->name('api.widget.lead');
    });
