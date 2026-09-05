<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.\App\Support\Branding::metaTitle() : \App\Support\Branding::metaTitle() }}
</title>

@if ($description = \App\Support\Branding::metaDescription())
    <meta name="description" content="{{ $description }}">
@endif

@if ($favicon = \App\Support\Branding::faviconUrl())
    <link rel="icon" href="{{ $favicon }}">
@else
    <link rel="icon" href="{{ route('pwa.icon', ['size' => 32]) }}" type="image/png" sizes="32x32">
@endif
<link rel="apple-touch-icon" href="{{ route('pwa.icon', ['size' => 180]) }}" sizes="180x180">

@php
    $applicationTheme = auth()->check() ? app(\App\Services\Themes\ThemeService::class)->effectiveFor(auth()->user()) : null;
@endphp
@if ($applicationTheme)
    <meta name="theme-color" content="{{ $applicationTheme->primary_light }}" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="{{ $applicationTheme->primary_dark }}" media="(prefers-color-scheme: dark)">
@else
    <meta name="theme-color" content="{{ \App\Support\Branding::accentColor() }}">
@endif
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="manifest" href="{{ route('manifest') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@if ($applicationTheme)
    <style data-application-theme>{!! app(\App\Services\Themes\ThemeService::class)->cssVariables($applicationTheme) !!}</style>
@endif
@fluxAppearance
@if ($code = \App\Support\Branding::customCodeHtml())
    <!-- approvedesk-custom-code -->
    {!! $code !!}
@endif
