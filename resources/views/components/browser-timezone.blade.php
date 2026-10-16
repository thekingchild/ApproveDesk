{{-- Sends the browser's IANA timezone to the Livewire component (CapturesBrowserTimezone). --}}
<span hidden x-data x-init="$wire.browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''"></span>
