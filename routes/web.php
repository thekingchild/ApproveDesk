<?php

use App\Http\Controllers\Admin\ResetUserPasswordController;
use App\Http\Controllers\Admin\ResetUserSecondFactorController;
use App\Http\Controllers\AnalyticsExportController;
use App\Http\Controllers\ApprovalQueueController;
use App\Http\Controllers\ApprovalReportController;
use App\Http\Controllers\Auth\RegistrationConfirmationController;
use App\Http\Controllers\BackupDestinationController;
use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\CspReportController;
use App\Http\Controllers\DownloadBackupSnapshotController;
use App\Http\Controllers\DownloadEvidencePackController;
use App\Http\Controllers\EmailApprovalController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\IntegrationCredentialController;
use App\Http\Controllers\NotificationChannelCredentialController;
use App\Http\Controllers\OAuthController;
use App\Http\Controllers\ProfileImageController;
use App\Http\Controllers\PublicWebsiteController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\PwaIconController;
use App\Http\Controllers\SlackInteractionsController;
use App\Http\Controllers\SubmissionFileDownloadController;
use App\Http\Controllers\SystemSettingsController;
use App\Http\Controllers\TeamsActionsController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\TestSystemMailController;
use App\Http\Controllers\TestTurnstileController;
use App\Http\Controllers\TurnstileCredentialController;
use App\Support\Branding;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicWebsiteController::class, 'home'])->name('home');

Route::get('manifest.webmanifest', function () {
    $name = Branding::name();

    return response()->json([
        'name' => $name,
        'short_name' => $name,
        'start_url' => '/dashboard',
        'display' => 'standalone',
        'background_color' => '#0a0a0b',
        'theme_color' => Branding::accentColor(),
        'icons' => [
            ['src' => '/pwa/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => '/pwa/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ],
    ])->header('Content-Type', 'application/manifest+json');
})->name('manifest');

Route::get('pwa/icon-{size}.png', PwaIconController::class)
    ->whereIn('size', ['32', '180', '192', '512'])
    ->name('pwa.icon');

Route::get('branding/assets/{file}', BrandingAssetController::class)
    ->where('file', '[A-Za-z0-9._-]+')
    ->name('branding.asset');

Route::post('integrations/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:telegram-webhook')
    ->name('integrations.telegram.webhook');

Route::post('integrations/slack/interactions', SlackInteractionsController::class)
    ->middleware('throttle:webhooks')
    ->name('integrations.slack.interactions');

Route::post('integrations/teams/actions', TeamsActionsController::class)
    ->middleware('throttle:webhooks')
    ->name('integrations.teams.actions');

Route::middleware('guest')->prefix('auth')->name('oauth.')->group(function (): void {
    Route::get('{provider}/redirect', [OAuthController::class, 'redirect'])->name('redirect');
    Route::get('{provider}/callback', [OAuthController::class, 'callback'])->name('callback');
});

/*
 | Self-registration email OTP confirmation (Phase UO.2). Deliberately
 | unauthenticated: the account exists but cannot hold a session yet.
 */
Route::middleware('guest')->prefix('registration/confirm')->name('registration.confirm.')->group(function (): void {
    Route::get('/', [RegistrationConfirmationController::class, 'show'])->name('show');
    Route::post('/verify', [RegistrationConfirmationController::class, 'verify'])
        ->middleware('throttle:registration-otp')
        ->name('verify');
    Route::post('/resend', [RegistrationConfirmationController::class, 'resend'])
        ->middleware('throttle:registration-resend')
        ->name('resend');
});

Route::middleware(['signed', 'throttle:approval-links', 'email-approval.second-factor'])->prefix('email-approvals')->name('approvals.email.')->group(function (): void {
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
    Route::livewire('admin/records', 'pages::admin.records')->name('admin.records.index');
});

Route::middleware(['auth', 'verified', 'can:operations.view'])->group(function (): void {
    Route::livewire('admin/operations', 'pages::admin.operations')->name('admin.operations.index');
});

Route::middleware(['auth', 'verified', 'can:backups.view'])->group(function (): void {
    Route::livewire('admin/backups', 'pages::admin.backups')->name('admin.backups.index');
});
Route::get('admin/backups/{snapshot}/download', DownloadBackupSnapshotController::class)
    ->middleware(['auth', 'verified', 'signed', 'can:backups.download'])
    ->name('admin.backups.download');
Route::post('admin/backups/destinations', [BackupDestinationController::class, 'store'])
    ->middleware(['auth', 'verified', 'can:backups.destinations.manage'])
    ->name('admin.backups.destinations.store');
Route::post('admin/backups/destinations/{destination}', [BackupDestinationController::class, 'update'])
    ->middleware(['auth', 'verified', 'can:backups.destinations.manage'])
    ->name('admin.backups.destinations.update');
Route::post('admin/backups/destinations/{destination}/test', [BackupDestinationController::class, 'test'])
    ->middleware(['auth', 'verified', 'can:backups.destinations.manage'])
    ->name('admin.backups.destinations.test');

Route::middleware(['auth', 'verified', 'can:updates.view'])->group(function (): void {
    Route::livewire('admin/updates', 'pages::admin.updates')->name('admin.updates.index');
});
Route::middleware(['auth', 'verified', 'can:branding.manage'])->group(function (): void {
    Route::livewire('settings/branding', 'pages::settings.branding')->name('settings.branding');
});
Route::middleware(['auth', 'verified', 'can:approval_settings.manage'])->group(function (): void {
    Route::livewire('settings/approvals', 'pages::settings.approvals')->name('settings.approvals');
});
Route::middleware(['auth', 'verified', 'can:security_settings.manage'])->group(function (): void {
    Route::livewire('settings/security-privacy', 'pages::settings.security-privacy')->name('settings.security-privacy');
    Route::post('settings/security-privacy/turnstile', TurnstileCredentialController::class)
        ->name('settings.turnstile.credentials');
    Route::post('settings/security-privacy/turnstile/test', TestTurnstileController::class)
        ->name('settings.turnstile.test');
});
Route::middleware(['auth', 'verified', 'can:notification_channels.manage'])->group(function (): void {
    Route::livewire('settings/notification-channels', 'pages::settings.notification-channels')->name('settings.notification-channels');
    Route::post('settings/notification-channels/credentials', NotificationChannelCredentialController::class)
        ->name('settings.notification-channels.credentials');
});
Route::middleware(['auth', 'verified', 'can:system_settings.manage', 'password.confirm'])->group(function (): void {
    Route::view('settings/system', 'pages.settings.system')->name('settings.system');
    Route::post('settings/system', SystemSettingsController::class)->name('settings.system.update');
    Route::post('settings/system/test-mail', TestSystemMailController::class)
        ->middleware('throttle:system-mail-test')
        ->name('settings.system.test-mail');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\Announcement'])->group(function (): void {
    Route::livewire('admin/announcements', 'pages::admin.announcements')->name('admin.announcements.index');
});
Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\Theme'])->group(function (): void {
    Route::livewire('admin/application-themes', 'pages::admin.application-themes')->name('admin.themes.index');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\User'])->group(function (): void {
    Route::livewire('admin/users', 'pages::admin.users')->name('admin.users.index');
    Route::post('admin/users/{user}/recovery/password', ResetUserPasswordController::class)
        ->middleware(['password.confirm', 'owner.reauthenticated'])
        ->name('admin.users.recovery.password');
    Route::post('admin/users/{user}/recovery/second-factor', ResetUserSecondFactorController::class)
        ->middleware(['password.confirm', 'owner.reauthenticated'])
        ->name('admin.users.recovery.second-factor');
    Route::livewire('admin/users/import', 'pages::admin.users-import')->name('admin.users.import');
    Route::get('admin/users/import/template', function () {
        $csv = "name,email,department,manager_email,phone,roles\nAmina Bello,amina.bello@example.com,Operations,manager@example.com,+15555550123,submitter\n";

        return response()->make($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-import-template.csv"',
        ]);
    })->name('admin.users.import.template');
});

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\Role'])->group(function (): void {
    Route::livewire('admin/roles', 'pages::admin.roles')->name('admin.roles.index');
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

Route::middleware(['auth', 'verified', 'can:viewAny,App\Models\IntegrationConnector'])->group(function (): void {
    Route::livewire('admin/integrations', 'pages::admin.integrations')->name('admin.integrations.index');
    Route::post('admin/integrations/{connector}/credentials', IntegrationCredentialController::class)
        ->middleware('throttle:integrations')
        ->name('admin.integrations.credentials');
});

Route::middleware(['auth', 'verified', 'can:reports.view'])->prefix('admin')->name('admin.')->group(function (): void {
    Route::livewire('analytics', 'pages::admin.analytics')->name('analytics.index');
    Route::get('analytics/export', AnalyticsExportController::class)
        ->middleware('throttle:exports')
        ->name('analytics.export');
});

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('profile-images/{user}', ProfileImageController::class)->name('profile-image.show');
    Route::post('settings/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('settings.push-subscriptions.store');
    Route::delete('settings/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('settings.push-subscriptions.destroy');

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
    Route::get('submissions/{submission}/approval-report', [ApprovalReportController::class, 'show'])
        ->name('submissions.approval-report.show');
    Route::get('submissions/{submission}/approval-report.pdf', [ApprovalReportController::class, 'download'])
        ->middleware('throttle:exports')
        ->name('submissions.approval-report.download');
    Route::livewire('submissions/{submission}', 'pages::submissions.show')->name('submissions.show');
    Route::livewire('approvals/inbox', 'pages::approvals.inbox')->name('approvals.inbox');
    Route::get('approvals/queue/{submission}/{direction}', ApprovalQueueController::class)
        ->whereIn('direction', ['previous', 'next'])
        ->name('approvals.queue');

    Route::get('submissions/{submission}/files/{file}', SubmissionFileDownloadController::class)
        ->middleware('signed')
        ->name('submissions.files.download');

    Route::get('submissions/{submission}/evidence', DownloadEvidencePackController::class)
        ->middleware('throttle:exports')
        ->name('submissions.evidence.download');

    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

if (app()->environment(['local', 'testing'])) {
    Route::view('design-system', 'pages.design-system')
        ->middleware(['auth', 'verified'])
        ->name('design-system');
}

require __DIR__.'/settings.php';

Route::get('/{path}', [PublicWebsiteController::class, 'asset'])
    ->where('path', '.*')
    ->name('website.asset');

// Browsers post Content Security Policy violation reports here (ADR 0005).
Route::post('csp-report', CspReportController::class)
    ->middleware('throttle:csp-report')
    ->name('csp.report');
