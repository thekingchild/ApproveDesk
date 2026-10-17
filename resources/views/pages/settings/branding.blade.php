<?php

use App\Actions\Settings\UpdateBrandingSettingsAction;
use App\DTOs\BrandingSettingsData;
use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
use App\Support\SecondFactorAssurance;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Branding settings')] class extends Component {
    use WithFileUploads;

    public string $tagline = '';

    public string $accent_color = '#4f46e5';

    public int $overlay = 40;

    public string $custom_css = '';

    public string $custom_code = '';

    /** HTTPS origins custom code may load from, one per line (ADR 0005). */
    public string $custom_code_origins = '';

    public bool $allow_registration = true;

    public bool $landing_page_enabled = false;

    public string $meta_title = '';

    public string $meta_description = '';

    public string $registration_mode = 'otp';

    public string $allowed_registration_domains = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $background = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $logo = null;

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $favicon = null;

    public ?string $current_background = null;

    public ?string $current_logo = null;

    public ?string $current_favicon = null;

    public bool $remove_background = false;

    public bool $remove_logo = false;

    public bool $remove_favicon = false;

    public function mount(): void
    {
        $this->authorize('branding.manage');
        $this->tagline = (string) Setting::get('brand.tagline', 'Approvals, clearly tracked.');
        $this->accent_color = (string) Setting::get('brand.accent_color', '#4f46e5');
        $this->overlay = (int) Setting::get('brand.login_background_overlay', '40');
        $this->custom_css = (string) Setting::get('brand.custom_css', '');
        $this->custom_code = (string) Setting::get('brand.custom_code', '');
        $pendingCode = session('branding.pending_custom_code');
        if (is_string($pendingCode) && $pendingCode !== '' && $this->canManageCustomCode()) {
            $this->custom_code = $pendingCode;
        }
        // Until the owner saves an explicit list, show the hosts the policy uses today.
        $this->custom_code_origins = implode("\n", \App\Support\Branding::customCodeOrigins());
        $pendingOrigins = session('branding.pending_custom_code_origins');
        if (is_string($pendingOrigins) && $this->canManageCustomCode()) {
            $this->custom_code_origins = $pendingOrigins;
        }
        $this->allow_registration = Setting::get('auth.allow_registration', 'true') !== 'false';
        $this->landing_page_enabled = Setting::get('brand.landing_page_enabled', 'false') === 'true';
        $this->meta_title = (string) Setting::get('brand.meta_title', '');
        $this->meta_description = (string) Setting::get('brand.meta_description', '');
        $this->registration_mode = (string) Setting::get('auth.registration_mode', 'otp');
        $this->allowed_registration_domains = (string) Setting::get('auth.allowed_registration_domains', '');
        $this->current_background = Setting::get('brand.login_background');
        $this->current_logo = Setting::get('brand.logo');
        $this->current_favicon = Setting::get('brand.favicon');
    }

    public function updatedBackground(): void
    {
        $this->validate([
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
    }

    public function updatedLogo(): void
    {
        $this->validate([
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
        ]);
    }

    public function updatedFavicon(): void
    {
        $this->validate([
            'favicon' => ['nullable', 'mimes:ico,png,jpg,jpeg,webp', 'max:512'],
        ]);
    }

    /** Clear a pending replacement so the stored asset stays in place. */
    public function keepCurrent(string $what): void
    {
        if (in_array($what, ['background', 'logo', 'favicon'], true)) {
            $this->{$what} = null;
            $this->resetValidation($what);
        }
    }

    public function save(UpdateBrandingSettingsAction $updateBranding): void
    {
        $this->authorize('branding.manage');

        $this->validate([
            'tagline' => ['required', 'string', 'max:120'],
            'accent_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'overlay' => ['required', 'integer', 'min:0', 'max:90'],
            'custom_css' => ['nullable', 'string', 'max:5000'],
            'custom_code' => ['nullable', 'string', 'max:8000'],
            'custom_code_origins' => ['nullable', 'string', 'max:2000'],
            'meta_title' => ['nullable', 'string', 'max:80'],
            'meta_description' => ['nullable', 'string', 'max:180'],
            'registration_mode' => ['required', \Illuminate\Validation\Rule::in(['immediate', 'otp'])],
            'allowed_registration_domains' => ['nullable', 'string', 'max:5000', 'regex:/^[a-z0-9\.\-\s\r\n,]*$/i'],
            'background' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'],
            'favicon' => ['nullable', 'mimes:ico,png,jpg,jpeg,webp', 'max:512'],
        ]);

        // Each allow-list entry must look like a hostname.
        foreach (preg_split('/[\r\n,]+/', $this->allowed_registration_domains) ?: [] as $domain) {
            $domain = trim((string) $domain);

            if ($domain !== '' && preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/', mb_strtolower($domain)) !== 1) {
                $this->addError('allowed_registration_domains', __('":domain" does not look like a domain name. One per line, e.g. example.com.', ['domain' => $domain]));

                return;
            }
        }

        foreach (preg_split('/[\r\n,]+/', $this->custom_code_origins) ?: [] as $origin) {
            $origin = rtrim(mb_strtolower(trim((string) $origin)), '/');

            if ($origin !== '' && ! \App\Support\Branding::isOrigin($origin)) {
                $this->addError('custom_code_origins', __('":origin" is not an HTTPS origin. One per line, e.g. https://www.googletagmanager.com.', ['origin' => $origin]));

                return;
            }
        }

        $existingCode = Setting::get('brand.custom_code');
        $existingCode = $existingCode === '' ? null : $existingCode;
        $submittedCode = $this->custom_code === '' ? null : $this->custom_code;

        // Widening where custom code may load from is as sensitive as the code itself.
        $currentOrigins = \App\Support\Branding::customCodeOrigins();
        $submittedOrigins = $this->canManageCustomCode() ? \App\Support\Branding::normalizeOrigins($this->custom_code_origins) : $currentOrigins;
        $addsOrigins = array_diff($submittedOrigins, $currentOrigins) !== [];

        if ($submittedCode !== $existingCode || $addsOrigins) {
            if ($this->canManageCustomCode()) {
                if (! $this->ensureCustomCodeSaveAuthorized()) {
                    return;
                }
            } else {
                $submittedCode = $existingCode;
                $this->custom_code = (string) ($existingCode ?? '');
            }
        }

        $updateBranding->execute(new BrandingSettingsData(
            tagline: $this->tagline,
            accentColor: $this->accent_color,
            overlay: $this->overlay,
            customCss: $this->custom_css === '' ? null : $this->custom_css,
            customCode: $submittedCode,
            customCodeOrigins: $this->canManageCustomCode() ? implode("\n", $submittedOrigins) : null,
            allowRegistration: $this->allow_registration,
            landingPageEnabled: $this->landing_page_enabled,
            registrationMode: $this->registration_mode,
            allowedRegistrationDomains: $this->allowed_registration_domains,
            metaTitle: $this->meta_title,
            metaDescription: $this->meta_description,
            background: $this->background,
            removeBackground: $this->remove_background,
            logo: $this->logo,
            removeLogo: $this->remove_logo,
            favicon: $this->favicon,
            removeFavicon: $this->remove_favicon,
        ), auth()->user());

        session()->forget(['branding.pending_custom_code', 'branding.pending_custom_code_origins']);

        $this->current_background = Setting::get('brand.login_background');
        $this->current_logo = Setting::get('brand.logo');
        $this->current_favicon = Setting::get('brand.favicon');
        $this->background = null;
        $this->logo = null;
        $this->favicon = null;
        $this->remove_background = false;
        $this->remove_logo = false;
        $this->remove_favicon = false;

        Flux::toast('Branding updated.', variant: 'success');
    }

    public function canManageCustomCode(): bool
    {
        return auth()->user()?->can('branding.custom_code.manage') === true;
    }

    private function ensureCustomCodeSaveAuthorized(): bool
    {
        $this->authorize('branding.custom_code.manage');

        $user = auth()->user();
        if (! $user instanceof User) {
            abort(403);
        }

        // A confirmation older than auth.password_timeout no longer counts (RV-SEC-16).
        $confirmedAt = session('auth.password_confirmed_at');
        if (! is_numeric($confirmedAt) || time() - (int) $confirmedAt > (int) config('auth.password_timeout', 10800)) {
            session()->put('url.intended', route('settings.branding'));
            session()->put('branding.pending_custom_code', $this->custom_code);
            session()->put('branding.pending_custom_code_origins', $this->custom_code_origins);
            $this->redirect(route('password.confirm'));

            return false;
        }

        if (! $user->hasSatisfiedSecondFactor() || ! app(SecondFactorAssurance::class)->satisfied(request(), $user)) {
            $this->addError('custom_code', __('Confirm an authenticator or passkey in this session before saving analytics code.'));

            return false;
        }

        return true;
    }

    public function previewStyle(): string
    {
        $url = $this->background?->temporaryUrl()
            ?? ($this->current_background && ! $this->remove_background
                ? Branding::urlFor($this->current_background)
                : null);

        if ($url !== null) {
            return 'background-image: url('.$url.'); background-size: cover; background-position: center;';
        }

        return 'background: linear-gradient(150deg, '.$this->accent_color.' 0%, #17173a 45%, #0a0a0b 100%);';
    }

    public function previewLogoUrl(): ?string
    {
        return $this->previewUploadUrl($this->logo, $this->current_logo, $this->remove_logo);
    }

    public function previewUploadUrl($upload, ?string $stored, bool $removing): ?string
    {
        if ($upload instanceof TemporaryUploadedFile) {
            return $upload->isPreviewable() ? $upload->temporaryUrl() : null;
        }

        if (is_string($stored) && $stored !== '' && ! $removing) {
            return Branding::urlFor($stored);
        }

        return null;
    }
} ?>

<x-pages::settings.layout :heading="__('Branding')" :subheading="__('Customize the login page and application identity')">
    <div>
        <div class="mb-4 flex items-center gap-1">
            <x-ui.help-link article="organization-settings" />
        </div>

        <div class="flex flex-col gap-6 xl:flex-row xl:items-start">
            <div class="w-full flex-1 space-y-6">

                {{-- Application identity --}}
                <flux:card>
                    <div class="flex items-center justify-between">
                        <flux:heading size="md">{{ __('Application identity') }}</flux:heading>
                        <p class="text-xs text-zinc-400">{{ __('Appears on login and throughout the app') }}</p>
                    </div>

                    <div class="mt-4 space-y-4">
                        <flux:input wire:model="meta_title" :label="__('Company name')" :description="__('Used throughout ApproveDesk and on printable approval records. Leave blank to use the installed application name.')" maxlength="80" data-test="branding-name" />
                        <flux:error name="meta_title" />

                        <flux:textarea wire:model="meta_description" :label="__('Meta description')" :description="__('Short description for search engines and link previews.')" rows="3" maxlength="180" />
                        <flux:error name="meta_description" />

                        <flux:separator />

                        <div class="grid gap-5 sm:grid-cols-2">
                            {{-- Logo --}}
                            <div data-test="branding-logo-card">
                                <p class="mb-2 text-sm font-medium">{{ __('Application logo') }}</p>
                                <div class="flex items-center gap-4 rounded-xl border border-dashed border-zinc-300 p-3 dark:border-zinc-700">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                        @if ($logo = $this->previewLogoUrl())
                                            <img src="{{ $logo }}" alt="" class="h-full w-full object-contain p-1" />
                                        @else
                                            <span class="text-xs font-semibold text-zinc-400">{{ __('None') }}</span>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1 space-y-1.5">
                                        <flux:input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" :placeholder="__('Choose image…')" />
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('PNG, JPG or WebP · max 1 MB') }}</p>
                                        <flux:error name="logo" />
                                    </div>
                                </div>
                                <div class="mt-2 space-y-1.5">
                                    @if ($logo instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                        <button type="button" wire:click="keepCurrent('logo')" class="text-xs text-zinc-500 underline decoration-dotted hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Keep current logo instead') }}</button>
                                    @elseif ($current_logo && ! $remove_logo)
                                        <flux:checkbox wire:model="remove_logo" :label="__('Remove current logo')" />
                                    @elseif ($remove_logo)
                                        <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('The current logo will be removed when you save.') }} <button type="button" wire:click="$set('remove_logo', false)" class="underline">{{ __('Keep it') }}</button></p>
                                    @endif
                                </div>
                            </div>

                            {{-- Favicon --}}
                            <div data-test="branding-favicon-card">
                                <p class="mb-2 text-sm font-medium">{{ __('Favicon') }}</p>
                                <div class="flex items-center gap-4 rounded-xl border border-dashed border-zinc-300 p-3 dark:border-zinc-700">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-800">
                                        @if ($faviconPreviewUrl = $this->previewUploadUrl($favicon, $current_favicon, $remove_favicon))
                                            <img src="{{ $faviconPreviewUrl }}" alt="" class="h-full w-full object-contain p-1" />
                                        @else
                                            <span class="text-xs font-semibold text-zinc-400">{{ __('None') }}</span>
                                        @endif
                                    </div>
                                    <div class="min-w-0 flex-1 space-y-1.5">
                                        <flux:input type="file" wire:model="favicon" accept=".ico,image/png,image/jpeg,image/webp" :placeholder="__('Choose file…')" />
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('ICO, PNG, JPG or WebP · max 512 KB · browser tab icon') }}</p>
                                        <flux:error name="favicon" />
                                    </div>
                                </div>
                                <div class="mt-2 space-y-1.5">
                                    @if ($favicon instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                        <button type="button" wire:click="keepCurrent('favicon')" class="text-xs text-zinc-500 underline decoration-dotted hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Keep current favicon instead') }}</button>
                                    @elseif ($current_favicon && ! $remove_favicon)
                                        <flux:checkbox wire:model="remove_favicon" :label="__('Remove current favicon')" />
                                    @elseif ($remove_favicon)
                                        <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('The current favicon will be removed when you save.') }} <button type="button" wire:click="$set('remove_favicon', false)" class="underline">{{ __('Keep it') }}</button></p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="space-y-1.5 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:checkbox wire:model="landing_page_enabled" :label="__('Show public landing page')" />
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('When off, the site address opens Login. Turn it on to show the public website.') }}</p>
                        </div>

                        <flux:checkbox wire:model="allow_registration" :label="__('Allow self-registration')" />

                        @if ($allow_registration)
                            <div class="space-y-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="registration-controls">
                                <flux:select wire:model="registration_mode" :label="__('After someone registers')">
                                    <flux:select.option value="otp">{{ __('Require email confirmation (recommended)') }}</flux:select.option>
                                    <flux:select.option value="immediate">{{ __('Grant immediate access') }}</flux:select.option>
                                </flux:select>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('With email confirmation, the account cannot sign in until the person enters the 6-digit code sent to their address. Codes expire after 10 minutes.') }}
                                </p>

                                <flux:textarea
                                    wire:model="allowed_registration_domains"
                                    :label="__('Registration domains')"
                                    rows="3"
                                    placeholder="{{ __('example.com') }}&#10;{{ __('mail.example.com') }}"
                                />
                                <flux:error name="allowed_registration_domains" />
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('One domain per line. Only people with an email at these domains may register — useful when only organization addresses should sign up. Leave empty to allow any email provider.') }}
                                </p>
                            </div>
                        @endif
                    </div>
                </flux:card>

                {{-- Login page appearance --}}
                <flux:card>
                    <div class="flex items-center justify-between">
                        <flux:heading size="md">{{ __('Login page') }}</flux:heading>
                        <p class="text-xs text-zinc-400">{{ __('What requesters see before they sign in') }}</p>
                    </div>

                    <div class="mt-4 space-y-4">
                        <flux:input wire:model="tagline" :label="__('Tagline')" placeholder="Approvals, clearly tracked." />

                        <flux:separator />

                        <div class="flex items-end gap-4">
                            <div class="w-16">
                                <flux:input wire:model="accent_color" type="color" :label="__('Accent color')" class="h-10 p-1" />
                            </div>
                            <flux:input wire:model="accent_color" :label="__('Hex value')" class="flex-1" />
                        </div>

                        <div>
                            <flux:input wire:model.live="overlay" type="range" min="0" max="90" :label="__('Background dark overlay (%)')" />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Only applies when a background image is set.') }}</p>
                        </div>

                        <div data-test="branding-background-card">
                            <div class="flex items-center gap-4 rounded-xl border border-dashed border-zinc-300 p-3 dark:border-zinc-700">
                                <div class="hidden h-16 w-24 shrink-0 overflow-hidden rounded-lg bg-zinc-100 sm:block dark:bg-zinc-800">
                                    @if ($bgThumb = ($background?->temporaryUrl() ?? (($current_background && ! $remove_background) ? \App\Support\Branding::urlFor($current_background) : null)))
                                        <img src="{{ $bgThumb }}" alt="" class="h-full w-full object-cover" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <p class="text-sm font-medium">{{ __('Login background image') }}</p>
                                    <flux:input type="file" wire:model="background" accept="image/*" :placeholder="__('Choose image…')" />
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('JPG, PNG or WebP · max 2 MB · shown at every screen width') }}</p>
                                    <flux:error name="background" />
                                </div>
                            </div>
                            <div class="mt-2 space-y-1.5">
                                @if ($background instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                    <button type="button" wire:click="keepCurrent('background')" class="text-xs text-zinc-500 underline decoration-dotted hover:text-zinc-700 dark:hover:text-zinc-300">{{ __('Keep current background instead') }}</button>
                                @elseif ($current_background && ! $remove_background)
                                    <flux:checkbox wire:model="remove_background" :label="__('Remove current background')" />
                                @elseif ($remove_background)
                                    <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('The current background will be removed when you save.') }} <button type="button" wire:click="$set('remove_background', false)" class="underline">{{ __('Keep it') }}</button></p>
                                @endif
                            </div>
                        </div>

                        <details class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <summary class="cursor-pointer text-sm font-medium">{{ __('Advanced: custom CSS') }}</summary>
                            <div class="mt-3 space-y-2">
                                <flux:textarea wire:model="custom_css" rows="4" placeholder="/* e.g. .brand-mark { border-radius: 0; } */" />
                                <flux:error name="custom_css" />
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('For fine-grained adjustments only. Applied after standard styles; invalid CSS is ignored by browsers.') }}</p>
                            </div>
                        </details>

                        @if ($this->canManageCustomCode())
                        <details class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                            <summary class="cursor-pointer text-sm font-medium">{{ __('Advanced: analytics and custom code') }}</summary>
                            <div class="mt-3 space-y-2">
                                <flux:textarea wire:model="custom_code" rows="6" placeholder="<!-- Google tag or other tracking snippet -->" data-test="custom-code" />
                                <flux:error name="custom_code" />
                                <flux:textarea wire:model="custom_code_origins" rows="3" :label="__('Allowed sites for this code')" placeholder="https://www.googletagmanager.com" data-test="custom-code-origins" />
                                <flux:error name="custom_code_origins" />
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('One HTTPS address per line, without a path. The browser lets custom code load scripts, images, frames, and connections only from these sites. Only the System owner can change this list, with the same confirmation as the code.') }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Optional. Paste a tracking snippet or JavaScript from your analytics provider. It is added to login, the signed-in app, and the public home page. Only the System owner can change this, after confirming their password and second factor. Do not paste secrets.') }}</p>
                            </div>
                        </details>
                        @endif
                    </div>
                </flux:card>

                <div class="flex items-center justify-end gap-4">
                    <flux:button variant="primary" wire:click="save" data-test="save-branding">{{ __('Save branding') }}</flux:button>
                </div>
            </div>

            {{-- Sticky live preview --}}
            <div class="w-full shrink-0 xl:w-96 xl:sticky xl:top-6" x-data="{ tab: 'login' }">
                <flux:card>
                    <div class="flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Live preview') }}</flux:heading>
                        <nav class="flex rounded-lg bg-zinc-100 p-0.5 text-sm dark:bg-zinc-800" aria-label="{{ __('Preview target') }}">
                            <button type="button" x-on:click="tab = 'login'" :class="tab === 'login' ? 'bg-white shadow dark:bg-zinc-900 text-zinc-900 dark:text-white' : 'text-zinc-500'" class="rounded-md px-3 py-1 font-medium transition" data-test="preview-tab-login">{{ __('Login') }}</button>
                            <button type="button" x-on:click="tab = 'browser'" :class="tab === 'browser' ? 'bg-white shadow dark:bg-zinc-900 text-zinc-900 dark:text-white' : 'text-zinc-500'" class="rounded-md px-3 py-1 font-medium transition" data-test="preview-tab-browser">{{ __('Browser') }}</button>
                        </nav>
                    </div>

                    {{-- Login panel preview --}}
                    <div x-show="tab === 'login'" class="relative mt-3 h-72 overflow-hidden rounded-lg" style="{{ $this->previewStyle() }}">
                        @if (str_contains($this->previewStyle(), 'background-image'))
                            <div class="absolute inset-0" style="background-color: rgba(0,0,0, {{ $overlay / 100 }})"></div>
                        @endif
                        <div class="relative z-10 flex h-full flex-col justify-between p-4 text-white">
                            <div class="flex items-center gap-2 text-sm font-medium">
                                @if ($logo = $this->previewLogoUrl())
                                    <img src="{{ $logo }}" alt="" class="h-7 w-7 rounded-md object-contain" />
                                @else
                                    <span class="flex h-7 w-7 items-center justify-center rounded-md" style="background: {{ $accent_color }}">AD</span>
                                @endif
                                {{ $meta_title !== '' ? $meta_title : config('app.name') }}
                            </div>
                            <div class="space-y-2">
                                <flux:heading size="lg">{{ $tagline ?: 'Approvals, clearly tracked.' }}</flux:heading>
                                <div class="rounded-lg bg-white/10 px-3 py-2 text-xs text-white/80 backdrop-blur-sm">sign in to continue</div>
                            </div>
                        </div>
                    </div>

                    {{-- Browser chrome preview --}}
                    <div x-show="tab === 'browser'" x-cloak class="mt-3 space-y-3">
                        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <div class="flex items-center gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                                @if ($favPrev = $this->previewUploadUrl($favicon, $current_favicon, $remove_favicon))
                                    <img src="{{ $favPrev }}" alt="" class="h-4 w-4 rounded-sm object-contain" />
                                @else
                                    <span class="inline-block h-4 w-4 rounded-sm bg-zinc-300 dark:bg-zinc-600"></span>
                                @endif
                                <span class="truncate text-xs text-zinc-500">{{ $meta_description !== '' || $meta_title !== '' ? trim(($meta_title !== '' ? $meta_title.' — ' : '').$meta_description) : config('app.name') }}</span>
                            </div>
                            <div class="flex items-center gap-2 px-3 py-2.5">
                                @if ($logo = $this->previewLogoUrl())
                                    <img src="{{ $logo }}" alt="" class="h-6 w-6 rounded-md object-contain" />
                                @else
                                    <span class="flex h-6 w-6 items-center justify-center rounded-md text-[10px] font-bold text-white" style="background: {{ $accent_color }}">AD</span>
                                @endif
                                <span class="text-sm font-medium">{{ $meta_title !== '' ? $meta_title : config('app.name') }}</span>
                            </div>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('How your name, description, logo, and favicon appear in a browser tab and the signed-in header.') }}</p>
                    </div>
                </flux:card>
            </div>
        </div>
    </div>
</x-pages::settings.layout>
