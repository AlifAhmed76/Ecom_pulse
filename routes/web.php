<?php

use App\Http\Controllers\ActionCenterController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\CourierImportController;
use App\Http\Controllers\CourierLossController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExperimentController;
use App\Http\Controllers\FileAnalysisController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RegisterController;
use App\Http\Controllers\SecurityController;
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
    Route::post('/system/llm-ping', [SystemController::class, 'pingLlm'])
        ->middleware(['role:owner,admin', 'throttle:10,1'])
        ->name('system.llm-ping');

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
    Route::get('/action-center', [ActionCenterController::class, 'index'])->name('action-center.index');
    Route::get('/action-center/findings/{finding}', [ActionCenterController::class, 'show'])->name('action-center.findings.show');
    Route::post('/action-center/findings/{finding}/ai-explain', [ActionCenterController::class, 'explain'])
        ->middleware('throttle:10,1')
        ->name('action-center.findings.ai-explain');
    Route::get('/experiments', [ExperimentController::class, 'index'])->name('experiments.index');
    Route::get('/experiments/create', [ExperimentController::class, 'create'])->name('experiments.create');
    Route::post('/experiments', [ExperimentController::class, 'store'])->name('experiments.store');
    Route::get('/experiments/{experiment}', [ExperimentController::class, 'show'])->name('experiments.show');
    Route::get('/chatbots', [ChatbotController::class, 'index'])->name('chatbots.index');
    Route::get('/chatbots/{chatbot}', [ChatbotController::class, 'show'])->name('chatbots.show');
    Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');

    Route::middleware('role:owner,admin')->group(function (): void {
        Route::post('/chatbots', [ChatbotController::class, 'store'])->name('chatbots.store');
        Route::post('/chatbots/{chatbot}', [ChatbotController::class, 'update'])->name('chatbots.update');
        Route::post('/chatbots/{chatbot}/token', [ChatbotController::class, 'issueToken'])->name('chatbots.token');
        Route::post('/chatbots/{chatbot}/documents', [ChatbotController::class, 'storeDocument'])->name('chatbots.documents.store');
        Route::delete('/chatbots/{chatbot}/documents/{document}', [ChatbotController::class, 'destroyDocument'])->name('chatbots.documents.destroy');
        Route::post('/chatbots/{chatbot}/test-ask', [ChatbotController::class, 'testAsk'])
            ->middleware('throttle:30,1')
            ->name('chatbots.test-ask');

        Route::get('/security', [SecurityController::class, 'index'])->name('security.index');
        Route::post('/security/events/{event}/review', [SecurityController::class, 'reviewEvent'])->name('security.events.review');
        Route::post('/security/rules/{rule}', [SecurityController::class, 'updateRule'])->name('security.rules.update');
        Route::post('/action-center/scan', [ActionCenterController::class, 'scan'])->name('action-center.scan');
        Route::post('/action-center/findings/{finding}/review', [ActionCenterController::class, 'review'])->name('action-center.findings.review');
        Route::post('/action-center/findings/{finding}/dismiss', [ActionCenterController::class, 'dismiss'])->name('action-center.findings.dismiss');
        Route::post('/experiments/{experiment}/approve', [ExperimentController::class, 'approve'])->name('experiments.approve');
        Route::post('/experiments/{experiment}/start', [ExperimentController::class, 'start'])->name('experiments.start');
        Route::post('/experiments/{experiment}/complete', [ExperimentController::class, 'complete'])->name('experiments.complete');
        Route::post('/experiments/{experiment}/cancel', [ExperimentController::class, 'cancel'])->name('experiments.cancel');

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
