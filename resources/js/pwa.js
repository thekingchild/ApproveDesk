function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return
    }

    navigator.serviceWorker.register('/sw.js').catch(() => {})
}

function bindInstallPrompt() {
    window.approveDeskInstallPrompt = null
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault()
        window.approveDeskInstallPrompt = event
        window.dispatchEvent(new CustomEvent('approvedesk-install-ready'))
    })
}

function bindOfflineDecisions() {
    const sync = () => {
        document.querySelectorAll('[data-requires-online]').forEach((node) => {
            if (navigator.onLine) {
                node.removeAttribute('disabled')
                node.removeAttribute('aria-disabled')
            } else {
                node.setAttribute('disabled', 'disabled')
                node.setAttribute('aria-disabled', 'true')
            }
        })
    }

    window.addEventListener('online', sync)
    window.addEventListener('offline', sync)
    sync()
}

function bindDraftCache() {
    const root = document.querySelector('[data-offline-draft]')
    if (!root) {
        return
    }

    const key = root.getAttribute('data-offline-draft')
    if (!key) {
        return
    }

    const persist = () => {
        const payload = {}
        root.querySelectorAll('input, textarea, select').forEach((field) => {
            if (!field.name || field.type === 'file' || field.type === 'password') {
                return
            }
            payload[field.name] = field.value
        })
        try {
            localStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), payload }))
        } catch (error) {
            // Quota errors are non-fatal.
        }
    }

    root.addEventListener('input', persist)
    window.addEventListener('offline', persist)
}

window.approveDeskPush = {
    async subscribe(publicKey) {
        const registration = await navigator.serviceWorker.ready
        const subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: this.urlBase64ToUint8Array(publicKey),
        })

        await fetch('/settings/push-subscriptions', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                Accept: 'application/json',
            },
            body: JSON.stringify(subscription.toJSON()),
        })
    },
    async unsubscribe() {
        const registration = await navigator.serviceWorker.ready
        const subscription = await registration.pushManager.getSubscription()
        if (!subscription) {
            return
        }
        await fetch('/settings/push-subscriptions', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                Accept: 'application/json',
            },
            body: JSON.stringify({ endpoint: subscription.endpoint }),
        })
        await subscription.unsubscribe()
    },
    urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
        const raw = atob(base64)
        const output = new Uint8Array(raw.length)
        for (let i = 0; i < raw.length; i += 1) {
            output[i] = raw.charCodeAt(i)
        }
        return output
    },
}

registerServiceWorker()
bindInstallPrompt()
bindOfflineDecisions()
document.addEventListener('DOMContentLoaded', bindDraftCache)
document.addEventListener('livewire:navigated', bindDraftCache)
document.addEventListener('livewire:navigated', bindOfflineDecisions)
