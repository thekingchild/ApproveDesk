@props(['at', 'format' => 'datetime', 'empty' => '—'])
{{--
    One way to show a moment (RV-ARC-17): in the viewer's own time zone, with
    the exact time and zone on hover. Formats: date, datetime, time, long,
    relative. Date-only values (due dates) are not moments; format them as
    dates without this component, so they never shift a day.
--}}
@if ($at === null)
    <span {{ $attributes }}>{{ $empty }}</span>
@else
    @php($zone = \App\Support\LocalTime::viewerTimezone())
    <time datetime="{{ $at->toIso8601String() }}" title="{{ \App\Support\LocalTime::forViewer($at, 'long') }} ({{ $zone }})" {{ $attributes }}>{{ $format === 'relative' ? $at->diffForHumans() : \App\Support\LocalTime::forViewer($at, $format) }}</time>
@endif
