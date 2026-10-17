// Remember this browser's IANA time zone so the server shows times in it
// (RV-ARC-17). Read by App\Support\LocalTime::viewerTimezone(); not secret,
// so it is excluded from cookie encryption.
try {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''
    const current = document.cookie.split('; ').find((part) => part.startsWith('approvedesk_tz='))

    if (zone !== '' && current !== `approvedesk_tz=${encodeURIComponent(zone)}`) {
        const secure = window.location.protocol === 'https:' ? '; secure' : ''
        document.cookie = `approvedesk_tz=${encodeURIComponent(zone)}; path=/; max-age=31536000; samesite=lax${secure}`
    }
} catch {
    // Older browsers without Intl time zones keep the installation's zone.
}
