<x-layouts::auth :title="__('Confirm approval')">
    <div class="flex flex-col gap-6">
        <x-auth-header :title="__('Confirm approval')" :description="__('Review the details below before approving this submission.')" />

        <flux:card class="space-y-4">
            <div>
                <flux:text class="text-xs uppercase tracking-wide text-zinc-500">{{ $submission->reference_number }}</flux:text>
                <flux:heading size="lg">{{ $submission->title }}</flux:heading>
            </div>
            <dl class="grid gap-3 text-sm">
                <div><dt class="text-zinc-500">{{ __('Approver') }}</dt><dd class="font-medium">{{ $approver->name }}</dd></div>
                <div><dt class="text-zinc-500">{{ __('Submitted by') }}</dt><dd class="font-medium">{{ $submission->submitter->name }}</dd></div>
                <div><dt class="text-zinc-500">{{ __('Current step') }}</dt><dd class="font-medium">{{ $step->name }}</dd></div>
                <div><dt class="text-zinc-500">{{ __('Link expires') }}</dt><dd class="font-medium">{{ $expiresAt->toDayDateTimeString() }}</dd></div>
            </dl>
        </flux:card>

        <form method="POST" action="{{ $approvalUrl }}">
            @csrf
            <flux:button type="submit" variant="primary" class="w-full" data-test="email-approve-button">
                {{ __('Approve submission') }}
            </flux:button>
        </form>

        <flux:text class="text-center text-xs text-zinc-500">
            {{ __('If you did not expect this request, close this page without approving it.') }}
        </flux:text>
    </div>
</x-layouts::auth>
