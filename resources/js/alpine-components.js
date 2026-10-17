// Alpine components and directives used by Blade views (ADR 0005 step 2).
//
// Alpine's CSP build cannot evaluate arrow functions, globals (window,
// navigator, Intl), optional chaining or more than one statement inside an
// attribute, so any view logic beyond a single expression lives here and the
// templates only call methods and read properties. These work the same under
// the standard build.
//
// Values come from data-* attributes, not @js() arguments: the CSP build's
// string parser does not understand the \u escapes @js() emits for quotes,
// < > and &.
//
// Registered on alpine:init, which Livewire fires on DOMContentLoaded, after
// this module has run.

// Focus the element (or its first visible field). x-show may not have revealed
// it yet when Alpine's nextTick runs, so retry for a few frames until it is
// visible; focusing a hidden field does nothing.
function focusFirstField(root, attempts = 5) {
    const fields = root.matches('input, textarea, select') ? [root] : [...root.querySelectorAll('input, textarea, select')]
    const field = fields.find((candidate) => candidate.type !== 'hidden' && candidate.offsetParent !== null)

    if (field) {
        field.focus()
    } else if (attempts > 0) {
        requestAnimationFrame(() => focusFirstField(root, attempts - 1))
    }
}

function isCancelled(error) {
    return Boolean(error && error.constructor && error.constructor.name === 'UserCancelledError')
}

function passkeysSupported() {
    return Boolean(window.Passkeys && window.Passkeys.isSupported())
}

function defaultPasskeyName() {
    const ua = navigator.userAgent
    const pick = (list) => {
        const match = list.find(({ pattern }) => pattern.test(ua))
        return match ? match.name : null
    }

    const browser = pick([
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ])
    const os = pick([
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
    ])

    return [browser, os].filter(Boolean).join(' on ') || ''
}

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine

    // x-autofocus: focus the element (or its first field) once it is shown.
    Alpine.directive('autofocus', (el) => {
        Alpine.nextTick(() => focusFirstField(el))
    })

    // <x-browser-timezone>: tell the Livewire component which zone the browser uses.
    Alpine.data('browserTimezone', () => ({
        init() {
            this.$wire.browserTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || ''
        },
    }))

    Alpine.data('passkeyRegistration', () => ({
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,

        init() {
            this.name = defaultPasskeyName()
            this.supported = passkeysSupported()
            window.addEventListener('passkeys:ready', () => { this.supported = passkeysSupported() }, { once: true })
        },

        canRegister() {
            return ! this.loading && this.name.trim() !== ''
        },

        async register() {
            if (! this.name.trim()) {
                return
            }

            this.loading = true
            this.error = null

            try {
                await window.Passkeys.register({ name: this.name })
                this.name = ''
                this.showForm = false
                await this.$wire.loadPasskeys()
            } catch (error) {
                if (! isCancelled(error)) {
                    this.error = error.message
                }
            } finally {
                this.loading = false
            }
        },

        cancel() {
            this.showForm = false
            this.name = ''
            this.error = null
        },
    }))

    Alpine.data('passkeyVerify', () => ({
        supported: false,
        loading: false,
        error: null,
        routes: { options: '', submit: '' },

        init() {
            this.routes = { options: this.$el.dataset.optionsUrl, submit: this.$el.dataset.submitUrl }
            this.supported = passkeysSupported()
            window.addEventListener('passkeys:ready', () => { this.supported = passkeysSupported() }, { once: true })
        },

        async verify() {
            this.loading = true
            this.error = null

            try {
                const response = await window.Passkeys.verify({ routes: this.routes })
                window.Livewire.navigate(response.redirect || '/dashboard')
            } catch (error) {
                if (! isCancelled(error)) {
                    this.error = error.message
                }
            } finally {
                this.loading = false
            }
        },
    }))

    Alpine.data('twoFactorChallenge', () => ({
        showRecoveryInput: false,
        code: '',
        recovery_code: '',

        init() {
            this.showRecoveryInput = this.$el.dataset.showRecoveryInput === 'true'
            if (! this.showRecoveryInput) {
                this.focusOtp()
            }
        },

        focusOtp() {
            this.$nextTick(() => {
                if (this.$refs.otp) {
                    focusFirstField(this.$refs.otp)
                }
            })
        },

        toggleInput() {
            this.showRecoveryInput = ! this.showRecoveryInput
            this.code = ''
            this.recovery_code = ''

            this.$nextTick(() => {
                if (this.showRecoveryInput && this.$refs.recovery_code) {
                    focusFirstField(this.$refs.recovery_code)
                } else {
                    this.focusOtp()
                }
            })
        },
    }))

    Alpine.data('copyToClipboard', () => ({
        copied: false,

        // Read when clicked: Livewire may fill data-copy-text after this component starts.
        async copy() {
            try {
                await navigator.clipboard.writeText(this.$root.dataset.copyText || '')
                this.copied = true
                setTimeout(() => { this.copied = false }, 1500)
            } catch {
                // Clipboard access denied; the value is still selectable.
            }
        },
    }))

    Alpine.data('pushSubscription', () => ({
        vapidPublicKey: '',

        init() {
            this.vapidPublicKey = this.$el.dataset.vapidPublicKey || ''
        },

        subscribe() {
            window.approveDeskPush.subscribe(this.vapidPublicKey).then(() => window.location.reload())
        },

        unsubscribe() {
            window.approveDeskPush.unsubscribe().then(() => window.location.reload())
        },
    }))

    // Request page keyboard shortcuts: [ and ] move through the queue, A and R open the decision dialogs.
    Alpine.data('approvalShortcuts', () => ({
        handle(event) {
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) || event.metaKey || event.ctrlKey || event.altKey) {
                return
            }

            // Read on each key press: the links change once the viewer has acted.
            const previous = this.$root.dataset.previousUrl
            const next = this.$root.dataset.nextUrl
            const key = event.key.toLowerCase()
            if (event.key === '[' && previous) {
                window.location.assign(previous)
            } else if (event.key === ']' && next) {
                window.location.assign(next)
            } else if (key === 'a' || key === 'r') {
                const trigger = document.querySelector(key === 'a' ? '[data-test=approve-modal-trigger]' : '[data-test=reject-modal-trigger]')
                if (trigger) {
                    trigger.click()
                }
            }
        },
    }))

    // Announcement message toolbar: wraps the selection in safe HTML tags.
    Alpine.data('announcementFormatter', () => ({
        labels: { linkPrompt: '', linkText: '', listItem: '' },

        init() {
            this.labels = {
                linkPrompt: this.$el.dataset.linkPrompt || '',
                linkText: this.$el.dataset.linkText || '',
                listItem: this.$el.dataset.listItem || '',
            }
        },

        field() {
            return this.$refs.message.matches('textarea') ? this.$refs.message : this.$refs.message.querySelector('textarea')
        },

        write(text, start, end) {
            const field = this.field()
            field.setRangeText(text, start, end, 'end')
            field.dispatchEvent(new Event('input', { bubbles: true }))
            field.focus()
        },

        replace(open, close, placeholder) {
            const field = this.field()
            const selected = field.value.slice(field.selectionStart, field.selectionEnd) || placeholder
            this.write(open + selected + close, field.selectionStart, field.selectionEnd)
        },

        list(tag) {
            const field = this.field()
            const selected = field.value.slice(field.selectionStart, field.selectionEnd) || this.labels.listItem
            const items = selected.split(/\r?\n/).filter((line) => line.trim() !== '').map((line) => '<li>' + line + '</li>').join('\n')
            this.write('<' + tag + '>\n' + items + '\n</' + tag + '>', field.selectionStart, field.selectionEnd)
        },

        link() {
            const url = window.prompt(this.labels.linkPrompt)
            if (! url || ! /^(https?:\/\/|mailto:|\/|#)/i.test(url)) {
                return
            }

            const safe = url.replace(/["'<>]/g, '')
            this.replace("<a href='" + safe + "'>", '</a>', this.labels.linkText)
        },
    }))
})
