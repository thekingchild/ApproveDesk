<?php

use App\Http\Controllers\InstallController;
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

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
