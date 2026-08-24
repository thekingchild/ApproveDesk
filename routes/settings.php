<?php

use App\Http\Controllers\UserDataExportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
    Route::livewire('settings/privacy', 'pages::settings.privacy')->name('privacy.edit');
    Route::get('settings/privacy/export', UserDataExportController::class)
        ->middleware('throttle:exports')
        ->name('privacy.export');
    Route::livewire('settings/notifications', 'pages::settings.notifications')->name('notifications.edit');
    Route::livewire('settings/out-of-office', 'pages::settings.out-of-office')->name('out-of-office.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');

    Route::livewire('settings/api-tokens', 'pages::settings.api-tokens')
        ->middleware([
            'password.confirm',
        ])
        ->name('api-tokens.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
