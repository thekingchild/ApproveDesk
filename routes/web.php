<?php

use App\Http\Controllers\AnalyticsExportController;
use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\EmailApprovalController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\SubmissionFileDownloadController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('branding/assets/{file}', BrandingAssetController::class)
    ->where('file', '[A-Za-z0-9._-]+')
    ->name('branding.asset');

Route::middleware('guest')->prefix('auth')->name('oauth.')->group(function (): void {
    Route::get('{provider}/redirect', [OAuthController::class, 'redirect'])->name('redirect');
    Route::get('{provider}/callback', [OAuthController::class, 'callback'])->name('callback');
});

Route::middleware(['signed', 'throttle:approval-links'])->prefix('email-approvals')->name('approvals.email.')->group(function (): void {
    Route::get('{instance}/{step}/{approver}', [EmailApprovalController::class, 'show'])->name('confirm');
    Route::post('{instance}/{step}/{approver}', [EmailApprovalController::class, 'approve'])->name('approve');
});

/*
 | First-run installer. Availability is enforced by EnsureAppConfigured:
 | reachable while uninstalled, 404 once the lock file exists.
 */
Route::prefix('install')->name('install.')->group(function (): void {
    Route::get('/', [InstallController::class, 'welcome'])->name('welcome');
    Route::get('database', [InstallController::class, 'showDatabase'])->name('database');
    Route::post('database', [InstallController::class, 'storeDatabase'])->name('database.store');
    Route::get('migrate', [InstallController::class, 'showMigrate'])->name('migrate');
    Route::post('migrate', [InstallController::class, 'runMigrate'])->name('migrate.run');
    Route::get('admin', [InstallController::class, 'showAdmin'])->name('admin');
    Route::post('admin', [InstallController::class, 'storeAdmin'])->name('admin.store');
    Route::get('done', [InstallController::class, 'done'])->name('done');
});

Route::middleware(['auth', 'verified', 'can:settings.manage'])->group(function (): void {
    Route::livewire('settings/branding', 'pages::settings.branding')->name('settings.branding');
    Route::livewire('settings/approvals', 'pages::settings.approvals')->name('settings.approvals');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\User'])->group(function (): void {
    Route::livewire('admin/users', 'pages::admin.users')->name('admin.users.index');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\Department'])->group(function (): void {
    Route::livewire('admin/departments', 'pages::admin.departments')->name('admin.departments.index');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\WorkflowTemplate'])->group(function (): void {
    Route::livewire('admin/workflow-templates', 'pages::admin.workflow-templates')->name('admin.workflow-templates.index');
    Route::livewire('admin/workflow-templates/{template}/builder', 'pages::admin.workflow-builder')->name('admin.workflow-templates.builder');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\RequestType'])->group(function (): void {
    Route::livewire('admin/request-types', 'pages::admin.request-types')->name('admin.request-types.index');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\WebhookEndpoint'])->group(function (): void {
    Route::livewire('admin/webhooks', 'pages::admin.webhooks')->name('admin.webhooks.index');
});

Route::middleware(['auth', 'verified', 'can:reports.view'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::livewire('analytics', 'pages::admin.analytics')->name('analytics.index');
    Route::get('analytics/export', AnalyticsExportController::class)->name('analytics.export');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('help', [HelpController::class, 'index'])->name('help');
    Route::get('help/{article}', [HelpController::class, 'show'])->name('help.show');
    Route::livewire('notifications', 'pages::notifications.index')->name('notifications.index');
    Route::livewire('submissions', 'pages::submissions.index')->name('submissions.index');
    Route::livewire('submissions/new', 'pages::submissions.choose-type')
        ->middleware('can:create,App\Models\Submission')
        ->name('submissions.choose-type');
    Route::livewire('submissions/create', 'pages::submissions.create')
        ->middleware('can:create,App\Models\Submission')
        ->name('submissions.create');
    Route::livewire('submissions/custom/{requestType}', 'pages::submissions.create-custom')
        ->middleware('can:use,requestType')
        ->name('submissions.custom.create');
    Route::livewire('submissions/{submission}/custom-edit', 'pages::submissions.create-custom')
        ->middleware('can:update,submission')
        ->name('submissions.custom.edit');
    Route::livewire('submissions/{submission}/edit', 'pages::submissions.create')
        ->middleware('can:update,submission')
        ->name('submissions.edit');
    Route::livewire('submissions/{submission}', 'pages::submissions.show')->name('submissions.show');
    Route::livewire('approvals/inbox', 'pages::approvals.inbox')->name('approvals.inbox');

    Route::get('submissions/{submission}/files/{file}', SubmissionFileDownloadController::class)
        ->middleware('signed')
        ->name('submissions.files.download');

    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

if (app()->environment(['local', 'testing'])) {
    Route::view('design-system', 'pages.design-system')
        ->middleware(['auth', 'verified'])
        ->name('design-system');
}

require __DIR__.'/settings.php';
