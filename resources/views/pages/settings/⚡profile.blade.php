<?php

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Profile settings')] class extends Component {
    use ProfileValidationRules, WithFileUploads;

    public string $name = '';
    public string $email = '';
    public $photo;

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();
        $validated = $this->validate($this->profileRules($user->id));
        $requestedName = $validated['name'];
        unset($validated['name']);
        $user->fill($validated);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();
        if (mb_strtolower(trim($requestedName)) !== mb_strtolower(trim($user->name))) {
            app(\App\Actions\Users\RequestDisplayNameChangeAction::class)->execute($user, $requestedName);
            $this->name = $user->name;
        }
        Flux::toast(variant: 'success', text: __('Profile updated. Name changes require approval.'));
    }

    public function uploadPhoto(): void
    {
        $this->validate(['photo' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048']]);
        app(\App\Actions\Users\StoreProfileImageAction::class)->execute(Auth::user(), $this->photo);
        $this->reset('photo');
        Flux::toast(variant: 'success', text: __('Profile photo updated.'));
    }

    public function removePhoto(): void
    {
        app(\App\Actions\Users\StoreProfileImageAction::class)->remove(Auth::user());
        Flux::toast(variant: 'success', text: __('Profile photo removed.'));
    }

    public function cancelNameChange(): void
    {
        $change = Auth::user()->displayNameChanges()->where('status', 'pending')->firstOrFail();
        app(\App\Actions\Users\RequestDisplayNameChangeAction::class)->cancel(Auth::user(), $change);
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();
        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));
            return;
        }
        $user->sendEmailVerificationNotification();
        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        return Auth::user() instanceof MustVerifyEmail && ! Auth::user()->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        return ! Auth::user() instanceof MustVerifyEmail || Auth::user()->hasVerifiedEmail();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')
    <flux:heading class="sr-only">{{ __('Profile settings') }}</flux:heading>
    <x-pages::settings.layout :heading="__('Profile')" :subheading="__('Update your photo and email, or request a reviewed display-name change')">
        @php($pendingName = auth()->user()->displayNameChanges()->where('status', 'pending')->latest()->first())
        @if($pendingName)
            <flux:callout variant="warning" icon="clock">{{ __('Name change to “:name” is awaiting review.', ['name' => $pendingName->proposed_name]) }} <flux:button size="sm" variant="ghost" wire:click="cancelNameChange">{{ __('Cancel') }}</flux:button></flux:callout>
        @endif
        <form wire:submit="uploadPhoto" class="my-6 space-y-3">
            @if(auth()->user()->profile_image_path)<img class="size-20 rounded-full object-cover" src="{{ route('profile-image.show', auth()->user()) }}" alt="{{ __('Profile photo') }}">@endif
            <flux:input wire:model="photo" type="file" accept="image/jpeg,image/png,image/webp" :label="__('Profile photo')" />
            <div class="flex gap-2"><flux:button type="submit">{{ __('Upload photo') }}</flux:button>@if(auth()->user()->profile_image_path)<flux:button type="button" variant="ghost" wire:click="removePhoto">{{ __('Remove photo') }}</flux:button>@endif</div>
        </form>
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Display name')" :description="__('A different name stays pending until another authorized administrator approves it.')" type="text" required autofocus autocomplete="name" />
            <div><flux:input wire:model="email" :label="__('Email')" type="email" required autocomplete="email" />
                @if ($this->hasUnverifiedEmail)<flux:text class="mt-4">{{ __('Your email address is unverified.') }} <flux:link class="text-sm cursor-pointer" wire:click.prevent="resendVerificationNotification">{{ __('Re-send verification email') }}</flux:link></flux:text>@endif
            </div>
            <flux:button variant="primary" type="submit" data-test="update-profile-button">{{ __('Save') }}</flux:button>
        </form>
        @if ($this->showDeleteUser)<livewire:pages::settings.delete-user-form />@endif
    </x-pages::settings.layout>
</section>
