<?php

use App\Actions\Settings\UpdateApprovalReferenceSchemaAction;
use App\Actions\Settings\UpdateApprovalSettingsAction;
use App\Actions\Settings\UpdateEmailApprovalSecurityAction;
use App\Actions\Settings\UpdateSelfApprovalPolicyAction;
use App\Actions\Settings\UpdateNudgeSettingsAction;
use App\Services\Collaboration\QuietHours;
use App\Services\Workflow\BusinessCalendar;
use App\Support\ApprovalReferenceSchema;
use App\Support\ApprovalLinks;
use App\Services\Workflow\SeparationOfDuties;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approval settings')] class extends Component {
    public int $emailLinkExpirationHours = ApprovalLinks::DEFAULT_EXPIRATION_HOURS;

    public string $referencePrefix = ApprovalReferenceSchema::DEFAULT_PREFIX;

    public string $referenceSeparator = ApprovalReferenceSchema::DEFAULT_SEPARATOR;

    public string $referenceYearPattern = ApprovalReferenceSchema::DEFAULT_YEAR_PATTERN;

    public string $referenceMonthPattern = ApprovalReferenceSchema::DEFAULT_MONTH_PATTERN;

    public int $referenceDigitLength = ApprovalReferenceSchema::DEFAULT_DIGIT_LENGTH;

    public string $referenceGenerationMode = ApprovalReferenceSchema::DEFAULT_GENERATION_MODE;

    public bool $emailApprovalSecondFactorRequired = false;

    public bool $blockSelfApproval = true;

    /** @var array<int, int> */
    public array $workingDays = [1, 2, 3, 4, 5];

    public string $holidays = '';

    public string $workingHoursStart = '';

    public string $workingHoursEnd = '';

    public string $quietHoursStart = '';

    public string $quietHoursEnd = '';

    public function mount(): void
    {
        $this->authorize('approval_settings.manage');
        $this->emailLinkExpirationHours = ApprovalLinks::expirationHours();
        if (auth()->user()?->can('approval_reference.manage')) {
            $schema = app(ApprovalReferenceSchema::class);
            $this->referencePrefix = $schema->prefix();
            $this->referenceSeparator = $schema->separator();
            $this->referenceYearPattern = $schema->yearPattern();
            $this->referenceMonthPattern = $schema->monthPattern();
            $this->referenceDigitLength = $schema->digitLength();
            $this->referenceGenerationMode = $schema->generationMode();
        }
        $this->emailApprovalSecondFactorRequired = ApprovalLinks::requiresSecondFactor();
        $this->blockSelfApproval = SeparationOfDuties::enabled();
        $calendar = app(BusinessCalendar::class);
        $this->workingDays = $calendar->workingDays();
        $this->holidays = implode("\n", $calendar->holidays());
        $hours = $calendar->workingHours();
        $this->workingHoursStart = $hours['start'] ?? '';
        $this->workingHoursEnd = $hours['end'] ?? '';
        $quiet = app(QuietHours::class)->window();
        $this->quietHoursStart = $quiet['start'] ?? '';
        $this->quietHoursEnd = $quiet['end'] ?? '';
    }

    public function referencePreview(): string
    {
        $parts = [strtoupper($this->referencePrefix)];

        if ($this->referenceYearPattern === 'yy') {
            $parts[] = now()->format('y');
        } elseif ($this->referenceYearPattern === 'yyyy') {
            $parts[] = now()->format('Y');
        }

        if ($this->referenceMonthPattern === 'mm') {
            $parts[] = now()->format('m');
        } elseif ($this->referenceMonthPattern === 'mmm') {
            $parts[] = strtoupper(now()->format('M'));
        }

        $parts[] = str_pad('1', max(4, min(12, $this->referenceDigitLength)), '0', STR_PAD_LEFT);

        return implode($this->referenceSeparator, $parts);
    }

    public function saveReferenceSchema(UpdateApprovalReferenceSchemaAction $action): void
    {
        $this->authorize('approval_reference.manage');

        $validated = $this->validate([
            'referencePrefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'referenceSeparator' => ['present', 'string', 'in:,-,/'],
            'referenceYearPattern' => ['required', 'in:none,yy,yyyy'],
            'referenceMonthPattern' => ['required', 'in:none,mm,mmm'],
            'referenceDigitLength' => ['required', 'integer', 'between:4,12'],
            'referenceGenerationMode' => ['required', 'in:sequential,random'],
        ]);

        $saved = $action->execute(auth()->user(), [
            'prefix' => $validated['referencePrefix'],
            'separator' => $validated['referenceSeparator'],
            'year_pattern' => $validated['referenceYearPattern'],
            'month_pattern' => $validated['referenceMonthPattern'],
            'digit_length' => $validated['referenceDigitLength'],
            'generation_mode' => $validated['referenceGenerationMode'],
        ]);

        $this->referencePrefix = $saved['prefix'];
        Flux::toast('Approval ID format saved.');
    }

    public function save(
        UpdateApprovalSettingsAction $updateSettings,
        UpdateNudgeSettingsAction $updateNudges,
        UpdateEmailApprovalSecurityAction $updateEmailSecurity,
        UpdateSelfApprovalPolicyAction $updateSelfApproval,
    ): void
    {
        $this->authorize('approval_settings.manage');
        $this->workingHoursStart = $this->normalizePostedTime($this->workingHoursStart);
        $this->workingHoursEnd = $this->normalizePostedTime($this->workingHoursEnd);
        $this->quietHoursStart = $this->normalizePostedTime($this->quietHoursStart);
        $this->quietHoursEnd = $this->normalizePostedTime($this->quietHoursEnd);

        $validated = $this->validate([
            'emailLinkExpirationHours' => ['required', 'integer', 'min:1', 'max:'.ApprovalLinks::MAX_EXPIRATION_HOURS],
            'emailApprovalSecondFactorRequired' => ['boolean'],
            'blockSelfApproval' => ['boolean'],
            'workingDays' => ['required', 'array', 'min:1'],
            'workingDays.*' => ['integer', 'min:1', 'max:7'],
            'holidays' => ['nullable', 'string', 'max:5000'],
            'workingHoursStart' => ['nullable', 'string', 'required_with:workingHoursEnd', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'workingHoursEnd' => ['nullable', 'string', 'required_with:workingHoursStart', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'quietHoursStart' => ['nullable', 'string', 'required_with:quietHoursEnd', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'quietHoursEnd' => ['nullable', 'string', 'required_with:quietHoursStart', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $start = $validated['workingHoursStart'] ?? '';
        $end = $validated['workingHoursEnd'] ?? '';
        if ($start !== '' && $end !== '' && strcmp($start, $end) >= 0) {
            $this->addError('workingHoursEnd', __('Office hours must end after they start on the same day.'));

            return;
        }

        $quietStart = $validated['quietHoursStart'] ?? '';
        $quietEnd = $validated['quietHoursEnd'] ?? '';
        if ($quietStart !== '' && $quietEnd !== '' && $quietStart === $quietEnd) {
            $this->addError('quietHoursEnd', __('Quiet hours start and end cannot be the same time.'));

            return;
        }

        try {
            $updateEmailSecurity->execute(
                $validated['emailApprovalSecondFactorRequired'],
                \Illuminate\Support\Facades\Auth::user(),
                request(),
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addError('emailApprovalSecondFactorRequired', $exception->getMessage());

            return;
        }

        $updateSelfApproval->execute($validated['blockSelfApproval']);

        $updateSettings->execute(
            $validated['emailLinkExpirationHours'],
            array_map('intval', $validated['workingDays']),
            $validated['holidays'] ?? '',
            $start,
            $end,
        );

        try {
            $updateNudges->execute($quietStart, $quietEnd);
        } catch (\InvalidArgumentException $exception) {
            $this->addError('quietHoursEnd', $exception->getMessage());

            return;
        }

        Flux::toast('Approval settings updated.', variant: 'success');
    }

    private function normalizePostedTime(mixed $value): string
    {
        $normalized = BusinessCalendar::normalizeTime(is_string($value) ? $value : null);

        return $normalized ?? (is_string($value) ? trim($value) : '');
    }
} ?>

<x-pages::settings.layout :heading="__('Approval settings')" :subheading="__('Configure email approval links and the business calendar used by workflow deadlines.')">
    <div class="mb-4 flex items-center gap-1">
        <x-ui.help-link article="approval-settings" />
    </div>

    @if (auth()->user()?->can('approval_reference.manage'))
        <flux:card class="mb-6 space-y-4" data-test="approval-reference-schema-card">
            <div>
                <flux:heading size="sm">{{ __('Approval ID format') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Choose how new approval IDs are generated. Existing IDs are never changed.') }}
                </flux:text>
            </div>

            <form wire:submit="saveReferenceSchema" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model.live.debounce.300ms="referencePrefix"
                        :label="__('Prefix')"
                        maxlength="12"
                        description="Letters and numbers only."
                        required
                    />

                    <flux:select wire:model.live="referenceSeparator" :label="__('Separator')">
                        <flux:select.option value="-">{{ __('Hyphen (-)') }}</flux:select.option>
                        <flux:select.option value="/">{{ __('Slash (/)') }}</flux:select.option>
                        <flux:select.option value="">{{ __('No separator') }}</flux:select.option>
                    </flux:select>

                    <flux:select wire:model.live="referenceYearPattern" :label="__('Year')">
                        <flux:select.option value="yyyy">{{ __('Four digits (YYYY)') }}</flux:select.option>
                        <flux:select.option value="yy">{{ __('Two digits (YY)') }}</flux:select.option>
                        <flux:select.option value="none">{{ __('Omit year') }}</flux:select.option>
                    </flux:select>

                    <flux:select wire:model.live="referenceMonthPattern" :label="__('Month')">
                        <flux:select.option value="none">{{ __('Omit month') }}</flux:select.option>
                        <flux:select.option value="mm">{{ __('Number (MM)') }}</flux:select.option>
                        <flux:select.option value="mmm">{{ __('Short name (MMM)') }}</flux:select.option>
                    </flux:select>

                    <flux:input
                        wire:model.live.debounce.300ms="referenceDigitLength"
                        type="number"
                        min="4"
                        max="12"
                        :label="__('ID digit length')"
                        required
                    />

                    <flux:select wire:model.live="referenceGenerationMode" :label="__('Generation')">
                        <flux:select.option value="sequential">{{ __('Sequential') }}</flux:select.option>
                        <flux:select.option value="random">{{ __('Random') }}</flux:select.option>
                    </flux:select>
                </div>

                <flux:callout icon="information-circle">
                    <flux:callout.heading>{{ __('Preview') }}</flux:callout.heading>
                    <flux:callout.text data-test="approval-reference-preview">
                        <span class="font-mono">{{ $this->referencePreview() }}</span>
                        <span class="block">{{ __('Sequential numbers restart only when an included year or month changes. Random IDs retry collisions automatically.') }}</span>
                    </flux:callout.text>
                </flux:callout>

                <flux:button type="submit" variant="primary">{{ __('Save approval ID format') }}</flux:button>
            </form>
        </flux:card>
    @endif

    <form wire:submit="save" class="space-y-6">
        <flux:input
            wire:model="emailLinkExpirationHours"
            type="number"
            min="1"
            :max="\App\Support\ApprovalLinks::MAX_EXPIRATION_HOURS"
            :label="__('Email approval link lifetime (hours)')"
            :description="__('Signed links stop working after this period. Choose between 1 and 720 hours.')"
            required
        />

        <flux:card class="space-y-3" data-test="self-approval-card">
            <div>
                <flux:heading size="sm">{{ __('Separation of duties') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Stop requesters from approving, deciding, or being delegated their own requests, even when a workflow step would include them.') }}
                </flux:text>
            </div>
            <flux:checkbox
                wire:model="blockSelfApproval"
                :label="__('Requesters cannot approve their own requests')"
                data-test="block-self-approval"
            />
            <flux:error name="blockSelfApproval" />
            <flux:text class="text-sm">
                {{ __('If a step would be approved only by the requester, the request cannot move to that step until an administrator changes who approves it.') }}
            </flux:text>
        </flux:card>

        <flux:card class="space-y-3" data-test="approval-link-second-factor-card">
            <div>
                <flux:heading size="sm">{{ __('Approval link identity') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Keep the signed link and its expiry, but require the named approver to sign in and verify an authenticator, recovery code, or passkey in this browser session.') }}
                </flux:text>
            </div>
            <flux:checkbox
                wire:model="emailApprovalSecondFactorRequired"
                :label="__('Require sign-in and a second factor for approval links')"
                data-test="email-approval-second-factor-required"
            />
            <flux:error name="emailApprovalSecondFactorRequired" />
            <flux:callout variant="warning" icon="exclamation-triangle">
                {{ __('This applies immediately to valid links already sent. Turning it off makes those links usable without sign-in again. While it is on, Teams and Slack cards open the confirmation page instead of approving in one click.') }}
            </flux:callout>
        </flux:card>

        <flux:checkbox.group wire:model="workingDays" :label="__('Working days')" class="grid gap-2 sm:grid-cols-4">
            @foreach ([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'] as $day => $label)
                <flux:checkbox :value="$day" :label="__($label)" />
            @endforeach
        </flux:checkbox.group>
        <flux:textarea wire:model="holidays" :label="__('Holidays')" :description="__('One YYYY-MM-DD date per line. Business-hour deadlines skip these days.')" rows="4" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input
                wire:model="workingHoursStart"
                type="time"
                step="60"
                :label="__('Office hours start')"
                :placeholder="__('09:00')"
            />
            <flux:input
                wire:model="workingHoursEnd"
                type="time"
                step="60"
                :label="__('Office hours end')"
                :placeholder="__('17:00')"
            />
        </div>
        <p class="text-sm text-zinc-500">
            {{ __('Optional. Leave both empty to count every hour of a working day (the current behaviour). Applies only to workflow steps that use Business hours. Elapsed-hour steps ignore this. Times are in the application timezone (:tz). A 24-hour working day is empty fields, not 00:00–23:59. Changing hours immediately affects in-flight Business-hour deadlines.', ['tz' => config('app.timezone')]) }}
        </p>
        <flux:error name="workingHoursStart" />
        <flux:error name="workingHoursEnd" />

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input
                wire:model="quietHoursStart"
                type="time"
                step="60"
                :label="__('Reminder quiet hours start')"
                :placeholder="__('22:00')"
            />
            <flux:input
                wire:model="quietHoursEnd"
                type="time"
                step="60"
                :label="__('Reminder quiet hours end')"
                :placeholder="__('07:00')"
            />
        </div>
        <p class="text-sm text-zinc-500">
            {{ __('Optional. Manual reminders wait until this window ends. Overnight ranges such as 22:00–07:00 are allowed. Empty fields send reminders immediately. Times are in the application timezone (:tz).', ['tz' => config('app.timezone')]) }}
        </p>
        <flux:error name="quietHoursStart" />
        <flux:error name="quietHoursEnd" />

        <flux:button type="submit" variant="primary">{{ __('Save approval settings') }}</flux:button>
    </form>
</x-pages::settings.layout>
