<?php

use App\Actions\Settings\UpdateApprovalSettingsAction;
use App\Services\Workflow\BusinessCalendar;
use App\Support\EmailApprovalLinks;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Approval settings')] class extends Component {
    public int $emailLinkExpirationHours = EmailApprovalLinks::DEFAULT_EXPIRATION_HOURS;

    /** @var array<int, int> */
    public array $workingDays = [1, 2, 3, 4, 5];

    public string $holidays = '';

    public string $workingHoursStart = '';

    public string $workingHoursEnd = '';

    public function mount(): void
    {
        $this->authorize('settings.manage');
        $this->emailLinkExpirationHours = EmailApprovalLinks::expirationHours();
        $calendar = app(BusinessCalendar::class);
        $this->workingDays = $calendar->workingDays();
        $this->holidays = implode("\n", $calendar->holidays());
        $hours = $calendar->workingHours();
        $this->workingHoursStart = $hours['start'] ?? '';
        $this->workingHoursEnd = $hours['end'] ?? '';
    }

    public function save(UpdateApprovalSettingsAction $updateSettings): void
    {
        $this->authorize('settings.manage');
        $this->workingHoursStart = $this->normalizePostedTime($this->workingHoursStart);
        $this->workingHoursEnd = $this->normalizePostedTime($this->workingHoursEnd);

        $validated = $this->validate([
            'emailLinkExpirationHours' => ['required', 'integer', 'min:1', 'max:'.EmailApprovalLinks::MAX_EXPIRATION_HOURS],
            'workingDays' => ['required', 'array', 'min:1'],
            'workingDays.*' => ['integer', 'min:1', 'max:7'],
            'holidays' => ['nullable', 'string', 'max:5000'],
            'workingHoursStart' => ['nullable', 'string', 'required_with:workingHoursEnd', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'workingHoursEnd' => ['nullable', 'string', 'required_with:workingHoursStart', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);

        $start = $validated['workingHoursStart'] ?? '';
        $end = $validated['workingHoursEnd'] ?? '';
        if ($start !== '' && $end !== '' && strcmp($start, $end) >= 0) {
            $this->addError('workingHoursEnd', __('Office hours must end after they start on the same day.'));

            return;
        }

        $updateSettings->execute(
            $validated['emailLinkExpirationHours'],
            array_map('intval', $validated['workingDays']),
            $validated['holidays'] ?? '',
            $start,
            $end,
        );
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
        <x-ui.help-link article="organization-settings" />
    </div>
    <form wire:submit="save" class="space-y-6">
        <flux:input
            wire:model="emailLinkExpirationHours"
            type="number"
            min="1"
            :max="\App\Support\EmailApprovalLinks::MAX_EXPIRATION_HOURS"
            :label="__('Email approval link lifetime (hours)')"
            :description="__('Signed links stop working after this period. Choose between 1 and 720 hours.')"
            required
        />

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

        <flux:button type="submit" variant="primary">{{ __('Save approval settings') }}</flux:button>
    </form>
</x-pages::settings.layout>
