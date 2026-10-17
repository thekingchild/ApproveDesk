<?php

namespace App\Providers;

use App\Contracts\DnsResolver;
use App\Contracts\ExternalAssigneeResolver;
use App\Enums\NotificationChannelKey;
use App\Enums\SystemRole;
use App\Events\SubmissionCreated;
use App\Events\WorkflowAdvanced;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowDelegated;
use App\Events\WorkflowInformationRequested;
use App\Events\WorkflowRejected;
use App\Events\WorkflowReturned;
use App\Listeners\ApplyOutOfOfficeFallbacks;
use App\Listeners\DispatchIntegrationActions;
use App\Listeners\DispatchWebhookDeliveries;
use App\Listeners\MarkSecondFactorAssurance;
use App\Listeners\NotifyCurrentApprovers;
use App\Listeners\NotifyDelegate;
use App\Listeners\NotifySubmitterOfDecision;
use App\Listeners\NotifyWatchersOfDecision;
use App\Listeners\RecordEmailDelivery;
use App\Listeners\RecordLoginEvent;
use App\Livewire\Hooks\SupportDomainExceptions;
use App\Models\Role;
use App\Models\User;
use App\Notifications\Channels\OptionalLaravelNotificationChannel;
use App\Policies\RolePolicy;
use App\Services\Install\Installer;
use App\Services\Integrations\ConnectorRegistry;
use App\Services\Integrations\Connectors\GoogleDriveConnector;
use App\Services\Integrations\Connectors\HttpConnector;
use App\Services\Integrations\Connectors\MailConnector;
use App\Services\Integrations\Connectors\SharePointConnector;
use App\Services\Integrations\Connectors\SlackConnector;
use App\Services\Integrations\Connectors\TeamsConnector;
use App\Services\Notifications\ChannelRegistry;
use App\Services\Notifications\Channels\DatabaseNotificationChannel;
use App\Services\Notifications\Channels\MailNotificationChannel;
use App\Services\Notifications\Channels\SlackNotificationChannel;
use App\Services\Notifications\Channels\SmsNotificationChannel;
use App\Services\Notifications\Channels\TeamsNotificationChannel;
use App\Services\Notifications\Channels\TelegramNotificationChannel;
use App\Services\Notifications\Channels\WebPushNotificationChannel;
use App\Services\Notifications\Channels\WhatsAppNotificationChannel;
use App\Services\Notifications\NotificationDeliveryRecorder;
use App\Services\Users\SystemOwnerService;
use App\Services\Workflow\AssigneeResolver;
use App\Support\CloudflareRocketLoader;
use App\Support\CorrelationId;
use App\Support\FeaturePermission;
use App\Support\OutboundDestination;
use App\Support\PermissionCache;
use App\Support\PermissionCatalogue;
use App\Support\PhpDnsResolver;
use App\Support\RateLimitProfiles;
use App\Support\SystemSettings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AssigneeResolver::class, function ($app): AssigneeResolver {
            return new AssigneeResolver($app->tagged(ExternalAssigneeResolver::class));
        });

        $this->app->tag([
            DatabaseNotificationChannel::class,
            MailNotificationChannel::class,
            TeamsNotificationChannel::class,
            SlackNotificationChannel::class,
            WhatsAppNotificationChannel::class,
            TelegramNotificationChannel::class,
            SmsNotificationChannel::class,
            WebPushNotificationChannel::class,
        ], 'notification.channels');

        $this->app->singleton(ChannelRegistry::class, function ($app): ChannelRegistry {
            return new ChannelRegistry($app->tagged('notification.channels'));
        });

        $this->app->tag([
            TeamsConnector::class,
            SlackConnector::class,
            GoogleDriveConnector::class,
            SharePointConnector::class,
            MailConnector::class,
            HttpConnector::class,
        ], 'integration.connectors');

        $this->app->singleton(ConnectorRegistry::class, function ($app): ConnectorRegistry {
            return new ConnectorRegistry($app->tagged('integration.connectors'));
        });

        $this->app->singleton(SystemOwnerService::class);
        $this->app->singleton(DnsResolver::class, PhpDnsResolver::class);
        $this->app->singleton(OutboundDestination::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // One place turns a refused domain rule into a message on every page (RV-ARC-19).
        Livewire::componentHook(SupportDomainExceptions::class);

        $this->configureDefaults();
        $this->configureCloudflareCompatibility();
        $this->configureSystemRuntime();

        // Spatie clears its cache when roles and permissions change through its
        // models; only migrations write those tables directly. Clearing on every
        // request cost ~22 ms, 3 queries and a file write (RV-PERF-13).
        Event::listen(MigrationsEnded::class, fn () => PermissionCache::forgetIfReady());

        Gate::policy(Role::class, RolePolicy::class);

        // Only the singleton System owner holds every ability implicitly.
        Gate::before(function (User $user, string $ability, array $arguments): ?bool {
            $target = $arguments[0] ?? null;
            if (in_array($ability, ['update', 'anonymize', 'recover'], true)
                && $target instanceof User
                && $target->isSystemOwner()) {
                return false;
            }

            if (PermissionCatalogue::find($ability) !== null && FeaturePermission::allows($user, $ability)) {
                return true;
            }

            return $user->isSystemOwner() ? true : null;
        });

        $this->defineNamedGates();

        RateLimiter::for('api', fn (Request $request): Limit => RateLimitProfiles::limit(
            'api',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        // Violation reports are anonymous; a page can send several, so allow a burst per IP.
        RateLimiter::for('csp-report', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));

        RateLimiter::for('approval-links', fn (Request $request): Limit => RateLimitProfiles::limit(
            'approval_links',
            (string) $request->ip(),
        ));
        RateLimiter::for('exports', fn (Request $request): Limit => RateLimitProfiles::limit(
            'exports',
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
        RateLimiter::for('webhooks', fn (Request $request): Limit => RateLimitProfiles::limit(
            'webhooks',
            (string) $request->ip(),
        ));
        RateLimiter::for('integrations', fn (Request $request): Limit => RateLimitProfiles::limit(
            'integrations',
            (string) $request->ip(),
        ));
        RateLimiter::for('telegram-webhook', fn (Request $request): Limit => RateLimitProfiles::limit(
            'webhooks',
            (string) $request->ip(),
        ));
        RateLimiter::for('system-mail-test', fn (Request $request): Limit => Limit::perMinute(4)->by(
            'system-mail-test:'.(string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));

        foreach ([
            NotificationChannelKey::Teams,
            NotificationChannelKey::Slack,
            NotificationChannelKey::WhatsApp,
            NotificationChannelKey::Telegram,
            NotificationChannelKey::WebPush,
        ] as $channel) {
            Notification::extend($channel->value, function ($app) use ($channel): OptionalLaravelNotificationChannel {
                return new OptionalLaravelNotificationChannel(
                    $app->make(ChannelRegistry::class)->driver($channel),
                    $app->make(NotificationDeliveryRecorder::class),
                );
            });
        }

        $this->registerWorkflowListeners();
        $this->registerCorrelationContext();
    }

    private function registerCorrelationContext(): void
    {
        Queue::createPayloadUsing(function (): array {
            return ['correlation_id' => CorrelationId::current()];
        });

        Queue::before(function (JobProcessing $event): void {
            $payload = $event->job->payload();
            $id = CorrelationId::accept(isset($payload['correlation_id']) ? (string) $payload['correlation_id'] : null);
            CorrelationId::set($id);
            Log::shareContext([
                'correlation_id' => $id,
                'job' => $event->job->resolveName(),
                'job_uuid' => $event->job->uuid(),
            ]);
        });
    }

    private function configureCloudflareCompatibility(): void
    {
        Event::listen(RequestHandled::class, function (RequestHandled $event): void {
            CloudflareRocketLoader::protect($event->response);
        });
    }

    /**
     * Explicit event-to-listener wiring for the notification side effects.
     * Domain logic stays inside WorkflowEngine; these only inform humans.
     */
    private function registerWorkflowListeners(): void
    {
        Event::listen(SubmissionCreated::class, NotifyCurrentApprovers::class);
        Event::listen(WorkflowAdvanced::class, NotifyCurrentApprovers::class);
        Event::listen(SubmissionCreated::class, ApplyOutOfOfficeFallbacks::class);
        Event::listen(WorkflowAdvanced::class, ApplyOutOfOfficeFallbacks::class);
        Event::listen(WorkflowDelegated::class, NotifyDelegate::class);

        Event::listen(WorkflowCompleted::class, NotifySubmitterOfDecision::class);
        Event::listen(WorkflowRejected::class, NotifySubmitterOfDecision::class);
        Event::listen(WorkflowReturned::class, NotifySubmitterOfDecision::class);
        Event::listen(WorkflowInformationRequested::class, NotifySubmitterOfDecision::class);

        Event::listen(WorkflowCompleted::class, NotifyWatchersOfDecision::class);
        Event::listen(WorkflowRejected::class, NotifyWatchersOfDecision::class);
        Event::listen(WorkflowReturned::class, NotifyWatchersOfDecision::class);
        Event::listen(WorkflowInformationRequested::class, NotifyWatchersOfDecision::class);

        Event::listen(SubmissionCreated::class, DispatchWebhookDeliveries::class);
        Event::listen(WorkflowAdvanced::class, DispatchWebhookDeliveries::class);
        Event::listen(WorkflowCompleted::class, DispatchWebhookDeliveries::class);
        Event::listen(WorkflowRejected::class, DispatchWebhookDeliveries::class);
        Event::listen(WorkflowReturned::class, DispatchWebhookDeliveries::class);
        Event::listen(WorkflowInformationRequested::class, DispatchWebhookDeliveries::class);
        Event::listen(WorkflowDelegated::class, DispatchWebhookDeliveries::class);
        Event::listen(WorkflowCompleted::class, DispatchIntegrationActions::class);
        Event::listen(Login::class, RecordLoginEvent::class);
        Event::listen([
            ValidTwoFactorAuthenticationCodeProvided::class,
            TwoFactorAuthenticationConfirmed::class,
            PasskeyVerified::class,
            PasskeyRegistered::class,
        ], MarkSecondFactorAssurance::class);
        Event::listen(NotificationSent::class, RecordEmailDelivery::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Surface N+1 queries in development and tests; never in production (RV-LAR-01).
        Model::preventLazyLoading(! app()->isProduction());
        // Reading a column the query did not select, or filling an attribute the
        // model does not accept, is a bug: fail loudly outside production (RV-LAR-03).
        Model::preventAccessingMissingAttributes(! app()->isProduction());
        Model::preventSilentlyDiscardingAttributes(! app()->isProduction());

        // Never preview an uploaded SVG from a signed temporary URL: it can carry script (RV-LAR-21).
        config(['livewire.temporary_file_upload.preview_mimes' => array_values(array_diff(
            (array) config('livewire.temporary_file_upload.preview_mimes', []),
            ['svg'],
        ))]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureSystemRuntime(): void
    {
        try {
            if (app(Installer::class)->isInstalled()) {
                SystemSettings::applyRuntimeConfig();
            }
        } catch (Throwable) {
            // Settings table or cache may be unavailable during installer and test bootstrap.
        }
    }

    /**
     * Abilities that are not about a model and not in the permission
     * catalogue (ADR 0008). Components and services ask for these instead of
     * comparing role names or calling isSystemOwner() on the actor. The owner
     * already passes every ability through Gate::before.
     */
    private function defineNamedGates(): void
    {
        Gate::define('system_owner', fn (User $user): bool => $user->isSystemOwner());
        Gate::define('approval_reference.manage', fn (User $user): bool => $user->isSystemOwner());
        Gate::define('dashboard.admin', fn (User $user): bool => $user->isSystemOwner() || $user->hasRole(SystemRole::Admin->value));
    }
}
