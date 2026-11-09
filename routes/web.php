<?php

use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
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
