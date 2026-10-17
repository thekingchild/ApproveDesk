<?php

use App\Actions\Settings\UpdateCustomCodeAction;
use App\Enums\StepUpStatus;
use App\Exceptions\StepUpRequiredException;
use App\Rules\HttpsOriginList;
use App\Support\Branding;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/*
 * Owner analytics and custom code on the Branding page (ADR 0006 split,
 * RV-ARC-10). UpdateCustomCodeAction owns the permission and the step-up;
 * this screen only sends the person to confirm their password and back.
 */
new class extends Component {
    public string $custom_code = '';

    /** HTTPS origins custom code may load from, one per line (ADR 0005). */
    public string $custom_code_origins = '';

    public function mount(): void
    {
        $this->authorize('branding.custom_code.manage');

        $this->custom_code = Branding::customCodeSource();
        // Until the owner saves an explicit list, show the hosts the policy uses today.
        $this->custom_code_origins = implode("\n", Branding::customCodeOrigins());

        // Restored after the password confirmation this screen sent them to.
        $pendingCode = session('branding.pending_custom_code');
        if (is_string($pendingCode) && $pendingCode !== '') {
            $this->custom_code = $pendingCode;
        }
        $pendingOrigins = session('branding.pending_custom_code_origins');
        if (is_string($pendingOrigins)) {
            $this->custom_code_origins = $pendingOrigins;
        }
    }

    public function save(UpdateCustomCodeAction $update): void
    {
        $this->authorize('branding.custom_code.manage');

        $this->validate([
            'custom_code' => ['nullable', 'string', 'max:8000'],
            'custom_code_origins' => ['nullable', 'string', 'max:2000', new HttpsOriginList],
        ]);

        try {
            $update->execute(Auth::user(), $this->custom_code, $this->custom_code_origins);
        } catch (StepUpRequiredException $exception) {
            if ($exception->status === StepUpStatus::NeedsPassword) {
                session()->put('url.intended', route('settings.branding'));
                session()->put('branding.pending_custom_code', $this->custom_code);
                session()->put('branding.pending_custom_code_origins', $this->custom_code_origins);
                $this->redirect(route('password.confirm'));

                return;
            }

            $this->addError('custom_code', $exception->userMessage());

            return;
        }

        session()->forget(['branding.pending_custom_code', 'branding.pending_custom_code_origins']);
        Flux::toast(__('Custom code saved.'), variant: 'success');
    }
} ?>

<flux:card data-test="custom-code-card">
    <div class="flex items-center justify-between">
        <flux:heading size="md">{{ __('Analytics and custom code') }}</flux:heading>
        <p class="text-xs text-zinc-400">{{ __('System owner only') }}</p>
    </div>

    <form wire:submit="save" class="mt-4 space-y-2">
        <flux:textarea wire:model="custom_code" rows="6" placeholder="<!-- Google tag or other tracking snippet -->" data-test="custom-code" />
        <flux:error name="custom_code" />
        <flux:textarea wire:model="custom_code_origins" rows="3" :label="__('Allowed sites for this code')" placeholder="https://www.googletagmanager.com" data-test="custom-code-origins" />
        <flux:error name="custom_code_origins" />
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('One HTTPS address per line, without a path. The browser lets custom code load scripts, images, frames, and connections only from these sites. Only the System owner can change this list, with the same confirmation as the code.') }}</p>
        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Optional. Paste a tracking snippet or JavaScript from your analytics provider. It is added to login, the signed-in app, and the public home page. Only the System owner can change this, after confirming their password and second factor. Do not paste secrets.') }}</p>
        <div class="flex justify-end pt-2">
            <flux:button type="submit" variant="primary" data-test="save-custom-code">{{ __('Save custom code') }}</flux:button>
        </div>
    </form>
</flux:card>
