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

    public function mount(): void
    {
        $this->authorize('settings.manage');
        $this->emailLinkExpirationHours = EmailApprovalLinks::expirationHours();
        $calendar = app(BusinessCalendar::class);
        $this->workingDays = $calendar->workingDays();
        $this->holidays = implode("\n", $calendar->holidays());
    }

    public function save(UpdateApprovalSettingsAction $updateSettings): void
    {
        $this->authorize('settings.manage');
        $validated = $this->validate([
            'emailLinkExpirationHours' => ['required', 'integer', 'min:1', 'max:'.EmailApprovalLinks::MAX_EXPIRATION_HOURS],
            'workingDays' => ['required', 'array', 'min:1'],
            'workingDays.*' => ['integer', 'min:1', 'max:7'],
            'holidays' => ['nullable', 'string', 'max:5000'],
        ]);

        $updateSettings->execute(
            $validated['emailLinkExpirationHours'],
            array_map('intval', $validated['workingDays']),
            $validated['holidays'] ?? '',
        );
        Flux::toast('Approval settings updated.', variant: 'success');
    }
} ?>

<x-pages::settings.layout :heading="__('Approval settings')" :subheading="__('Configure email approval links and the business calendar used by workflow deadlines.')">
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

        <flux:button type="submit" variant="primary">{{ __('Save approval settings') }}</flux:button>
    </form>
</x-pages::settings.layout>
