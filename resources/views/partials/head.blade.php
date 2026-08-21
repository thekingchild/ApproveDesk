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
    <link rel="apple-touch-icon" href="{{ $favicon }}">
@else
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
@endif

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
