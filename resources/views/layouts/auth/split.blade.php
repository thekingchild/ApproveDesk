<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <style>
            :root {
                --brand-accent: {{ \App\Support\Branding::accentColor() }};
            }
        </style>
        @if ($css = \App\Support\Branding::customCss())
            <style>{{ $css }}</style>
        @endif
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0">
            <div class="relative hidden h-full flex-col p-10 text-white lg:flex dark:border-e dark:border-neutral-800">
                @php($background = \App\Support\Branding::backgroundUrl())
                @if ($background)
                    <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('{{ $background }}')"></div>
                    <div class="absolute inset-0" style="background-color: rgba(0,0,0, {{ \App\Support\Branding::overlayPercent() / 100 }})"></div>
                @else
                    <div class="absolute inset-0" style="background: linear-gradient(150deg, var(--brand-accent) 0%, #17173a 45%, #0a0a0b 100%)"></div>
                @endif

                <a href="{{ route('home') }}" class="relative z-20 flex items-center text-lg font-medium" wire:navigate>
                    <span class="flex h-10 w-10 items-center justify-center rounded-md">
                        <x-app-logo-icon class="me-2 h-7 fill-current text-white" />
                    </span>
                    {{ config('app.name', 'ApproveDesk') }}
                </a>

                <div class="relative z-20 mt-auto">
                    <blockquote class="space-y-2">
                        <flux:heading size="lg">{{ \App\Support\Branding::tagline() }}</flux:heading>
                        <footer>
                            <flux:heading>
                                <span class="opacity-70">{{ config('app.name', 'ApproveDesk') }}</span>
                            </flux:heading>
                        </footer>
                    </blockquote>
                </div>
            </div>
            <div class="w-full lg:p-8">
                <div class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]">
                    <a href="{{ route('home') }}" class="z-20 flex flex-col items-center gap-2 font-medium lg:hidden" wire:navigate>
                        <span class="flex h-9 w-9 items-center justify-center rounded-md">
                            <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                        </span>

                        <span class="sr-only">{{ config('app.name', 'ApproveDesk') }}</span>
                    </a>
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
