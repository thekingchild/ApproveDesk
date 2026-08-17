<?php

namespace App\Providers;

use App\Events\SubmissionCreated;
use App\Events\WorkflowAdvanced;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowDelegated;
use App\Events\WorkflowInformationRequested;
use App\Events\WorkflowRejected;
use App\Events\WorkflowReturned;
use App\Listeners\NotifyCurrentApprovers;
use App\Listeners\NotifyDelegate;
use App\Listeners\NotifySubmitterOfDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();

        // super-admin holds every ability implicitly; no permission matrix entry.
        Gate::before(fn ($user, string $ability) => $user->hasRole('super-admin') ? true : null);

        $this->registerWorkflowListeners();
    }

    /**
     * Explicit event-to-listener wiring for the notification side effects.
     * Domain logic stays inside WorkflowEngine; these only inform humans.
     */
    private function registerWorkflowListeners(): void
    {
        Event::listen(SubmissionCreated::class, NotifyCurrentApprovers::class);
        Event::listen(WorkflowAdvanced::class, NotifyCurrentApprovers::class);
        Event::listen(WorkflowDelegated::class, NotifyDelegate::class);

        Event::listen(WorkflowCompleted::class, NotifySubmitterOfDecision::class);
        Event::listen(WorkflowRejected::class, NotifySubmitterOfDecision::class);
        Event::listen(WorkflowReturned::class, NotifySubmitterOfDecision::class);
        Event::listen(WorkflowInformationRequested::class, NotifySubmitterOfDecision::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

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
}
