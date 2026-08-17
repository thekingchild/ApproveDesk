<?php

use App\Http\Controllers\InstallController;
use App\Http\Controllers\SubmissionFileDownloadController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

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
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\User'])->group(function (): void {
    Route::livewire('admin/users', 'pages::admin.users')->name('admin.users.index');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\Department'])->group(function (): void {
    Route::livewire('admin/departments', 'pages::admin.departments')->name('admin.departments.index');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\WorkflowTemplate'])->group(function (): void {
    Route::livewire('admin/workflow-templates', 'pages::admin.workflow-templates')->name('admin.workflow-templates.index');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::livewire('submissions', 'pages::submissions.index')->name('submissions.index');
    Route::livewire('submissions/create', 'pages::submissions.create')
        ->middleware('can:create,App\Models\Submission')
        ->name('submissions.create');
    Route::livewire('submissions/{submission}', 'pages::submissions.show')->name('submissions.show');
    Route::livewire('approvals/inbox', 'pages::approvals.inbox')->name('approvals.inbox');

    Route::get('submissions/{submission}/files/{file}', SubmissionFileDownloadController::class)
        ->middleware('signed')
        ->name('submissions.files.download');

    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
