@props(['label' => 'Loading content'])

<div {{ $attributes->class('ad-skeleton') }} role="status" aria-label="{{ __($label) }}">
    <span class="sr-only">{{ __($label) }}</span>
    {{ $slot->isEmpty() ? 'Loading' : $slot }}
</div>
