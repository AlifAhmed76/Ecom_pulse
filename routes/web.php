<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CourierImportController;
use App\Http\Controllers\CourierLossController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileAnalysisController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\WebsiteController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:6,1')
        ->name('login.post');

    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('register.post');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/metrics/dashboard.json', [DashboardController::class, 'json'])->name('metrics.dashboard.json');
    Route::post('/dashboard/refresh', [DashboardController::class, 'refresh'])
        ->middleware(['role:owner,admin', 'throttle:10,1'])
        ->name('dashboard.refresh');

    Route::get('/workspace', [WorkspaceController::class, 'show'])
        ->middleware('role:owner,admin')
        ->name('workspace.show');

    Route::get('/system', [SystemController::class, 'index'])
        ->middleware('role:owner,admin')
        ->name('system.index');
    Route::post('/system/cache-clear', [SystemController::class, 'clearCache'])
        ->middleware(['role:owner,admin', 'throttle:10,1'])
        ->name('system.cache-clear');

    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('role:owner,admin')
        ->name('audit.index');
    Route::post('/audit/verify', [AuditController::class, 'verify'])
        ->middleware('role:owner,admin')
        ->name('audit.verify');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/courier-loss', [CourierLossController::class, 'index'])->name('courier-loss.index');

    Route::middleware('role:owner,admin')->group(function (): void {
        Route::post('/courier-loss/scan', [CourierLossController::class, 'scan'])->name('courier-loss.scan');
        Route::post('/courier-loss/flags/{flag}/review', [CourierLossController::class, 'review'])->name('courier-loss.flags.review');

        Route::get('/courier-imports', [CourierImportController::class, 'index'])->name('courier-imports.index');
        Route::post('/courier-imports', [CourierImportController::class, 'upload'])->name('courier-imports.upload');
        Route::get('/courier-imports/{batch}/map', [CourierImportController::class, 'map'])->name('courier-imports.map');
        Route::post('/courier-imports/{batch}/confirm', [CourierImportController::class, 'confirm'])->name('courier-imports.confirm');
        Route::get('/courier-imports/{batch}', [CourierImportController::class, 'show'])->name('courier-imports.show');
        Route::delete('/courier-imports/{batch}', [CourierImportController::class, 'discard'])->name('courier-imports.discard');

        Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
        Route::post('/imports', [ImportController::class, 'upload'])->name('imports.upload');
        Route::get('/imports/{batch}/map', [ImportController::class, 'map'])->name('imports.map');
        Route::post('/imports/{batch}/confirm', [ImportController::class, 'confirm'])->name('imports.confirm');
        Route::get('/imports/{batch}', [ImportController::class, 'show'])->name('imports.show');
        Route::delete('/imports/{batch}', [ImportController::class, 'discard'])->name('imports.discard');

        Route::get('/file-analysis', [FileAnalysisController::class, 'index'])->name('analysis.index');
        Route::post('/file-analysis', [FileAnalysisController::class, 'analyze'])->name('analysis.analyze');
        Route::get('/file-analysis/{analysis}', [FileAnalysisController::class, 'show'])->name('analysis.show');
    });

    Route::get('/websites', [WebsiteController::class, 'index'])->name('websites.index');
    Route::get('/websites/create', [WebsiteController::class, 'create'])->name('websites.create');
    Route::post('/websites', [WebsiteController::class, 'store'])->name('websites.store');
    Route::get('/websites/{website}', [WebsiteController::class, 'show'])->name('websites.show');
    Route::delete('/websites/{website}', [WebsiteController::class, 'destroy'])->name('websites.destroy');

    Route::post('/websites/{website}/credentials', [CredentialController::class, 'store'])
        ->name('websites.credentials.store');
    Route::post('/websites/{website}/credentials/{credential}/rotate', [CredentialController::class, 'rotate'])
        ->name('websites.credentials.rotate');
    Route::post('/websites/{website}/credentials/{credential}/revoke', [CredentialController::class, 'revoke'])
        ->name('websites.credentials.revoke');
});
