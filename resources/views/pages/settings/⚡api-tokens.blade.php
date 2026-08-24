<?php

use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('API tokens')] class extends Component {
    public string $tokenName = '';

    /** @var list<string> */
    public array $abilities = ['submissions:read'];

    public ?string $newToken = null;

    /** @return array<string, string> */
    #[Computed]
    public function availableAbilities(): array
    {
        $user = Auth::user();
        $abilities = [
            'submissions:read' => __('Read submissions you can access'),
            'request-types:read' => __('Read published request-type schemas'),
        ];

        if ($user->can('create', \App\Models\Submission::class)) {
            $abilities['submissions:write'] = __('Create and submit your submissions');
        }

        if ($user->can('approvals.act')) {
            $abilities['approvals:act'] = __('Record workflow actions assigned to you');
        }

        return $abilities;
    }

    /** @return \Illuminate\Support\Collection<int, \Laravel\Sanctum\PersonalAccessToken> */
    #[Computed]
    public function tokens(): \Illuminate\Support\Collection
    {
        return Auth::user()->tokens()
            ->select(['id', 'name', 'abilities', 'last_used_at', 'created_at'])
            ->latest()
            ->get();
    }

    public function create(): void
    {
        $user = Auth::user();

        if (\App\Support\SecuritySettings::twoFactorRequired() && ! $user->hasSatisfiedSecondFactor()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'tokenName' => __('A second factor is required before you can create a token.'),
            ]);
        }

        $validated = $this->validate([
            'tokenName' => ['required', 'string', 'min:3', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['required', 'string', Rule::in(array_keys($this->availableAbilities))],
        ]);

        $this->newToken = Auth::user()->createToken($validated['tokenName'], $validated['abilities'])->plainTextToken;
        $this->reset('tokenName');
        $this->abilities = ['submissions:read'];
        unset($this->tokens);

        Flux::toast('API token created. Copy it now; it will not be shown again.', variant: 'success');
    }

    public function revoke(int $tokenId): void
    {
        Auth::user()->tokens()->whereKey($tokenId)->delete();
        unset($this->tokens);

        Flux::toast('API token revoked.', variant: 'success');
    }

    public function dismissNewToken(): void
    {
        $this->newToken = null;
    }
} ?>

<x-pages::settings.layout :heading="__('API tokens')" :subheading="__('Create scoped tokens for integrations and scripts.')">
    <div class="space-y-8">
        @if ($newToken)
            <flux:callout variant="success" icon="key">
                <flux:callout.heading>{{ __('Copy this token now') }}</flux:callout.heading>
                <flux:callout.text>{{ __('For security, this value is shown only once.') }}</flux:callout.text>
                <div class="mt-3 flex gap-2">
                    <flux:input readonly :value="$newToken" aria-label="{{ __('New API token') }}" />
                    <flux:button wire:click="dismissNewToken" variant="filled">{{ __('Done') }}</flux:button>
                </div>
            </flux:callout>
        @endif

        <form wire:submit="create" class="space-y-5">
            <flux:input wire:model="tokenName" :label="__('Token name')" :placeholder="__('e.g. Finance integration')" required />

            <flux:checkbox.group wire:model="abilities" :label="__('Permissions')">
                @foreach ($this->availableAbilities as $ability => $description)
                    <flux:checkbox :value="$ability" :label="$ability" :description="$description" />
                @endforeach
            </flux:checkbox.group>
            <flux:error name="abilities" />

            <flux:button type="submit" variant="primary" icon="plus">{{ __('Create token') }}</flux:button>
        </form>

        <div>
            <flux:heading size="sm">{{ __('Active tokens') }}</flux:heading>
            <div class="mt-3 space-y-3">
                @forelse ($this->tokens as $token)
                    <flux:card class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="font-medium">{{ $token->name }}</p>
                            <p class="mt-1 text-xs text-zinc-500">{{ implode(', ', $token->abilities) }} · {{ __('Created :when', ['when' => $token->created_at->diffForHumans()]) }} · {{ __('Last used :when', ['when' => $token->last_used_at?->diffForHumans() ?? __('never')]) }}</p>
                        </div>
                        <flux:button wire:click="revoke({{ $token->id }})" variant="danger" size="sm">{{ __('Revoke') }}</flux:button>
                    </flux:card>
                @empty
                    <p class="text-sm text-zinc-500">{{ __('No API tokens yet.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</x-pages::settings.layout>
