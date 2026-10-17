import './pwa.js'
import './viewer-timezone.js'
import './confirm.js'
import './alpine-components.js'

// Form builder local-draft autosave (P6B-UI-04): stores builder state in
// this browser only, debounced; the server draft stays the durable record.
// Registered with Alpine.data so the CSP build can use it (ADR 0005).
const requestTypeBuilderDraft = () => ({
    savedAt: '',
    timer: null,
    key: '',
    // A stored draft waiting for Restore or Discard. Opening the builder resets
    // the form, which would otherwise autosave over it before anyone chooses.
    pending: false,

    init() {
        const key = 'approvedesk.request-type-builder.' + (this.$wire.editingTypeId === null ? 'new' : this.$wire.editingTypeId)
        this.key = key
        const stored = localStorage.getItem(key)

        if (stored) {
            try {
                const parsed = JSON.parse(stored)

                if (parsed && parsed.payload) {
                    this.pending = true
                    this.savedAt = parsed.savedAt || ''
                    this.$wire.localDraftSavedAt = parsed.savedAt || ''
                    this.$wire.localDraftAvailable = true
                }
            } catch {
                localStorage.removeItem(key)
            }
        }

        // Debounced autosave on any builder change (name or fields).
        this.$wire.$watch('fields', () => this.saveDraft())
        this.$wire.$watch('name', () => this.saveDraft())
    },

    saveDraft() {
        if (this.pending) {
            return
        }

        clearTimeout(this.timer)
        this.timer = setTimeout(() => {
            this.$wire.localDraftSnapshot().then((snapshot) => {
                const payload = { savedAt: new Date().toLocaleTimeString(), payload: snapshot }
                localStorage.setItem(this.key, JSON.stringify(payload))
                this.savedAt = payload.savedAt
            })
        }, 800)
    },

    restoreDraft() {
        const stored = localStorage.getItem(this.key)

        if (! stored) {
            return
        }

        try {
            const parsed = JSON.parse(stored)
            if (parsed.payload) {
                this.$wire.restoreLocalDraft(parsed.payload)
            }
            localStorage.removeItem(this.key)
            this.pending = false
            this.savedAt = ''
        } catch {
            localStorage.removeItem(this.key)
        }
    },

    discardDraft() {
        this.pending = false
        localStorage.removeItem(this.key)
        this.$wire.discardLocalDraft()
        this.savedAt = ''
    },
})

const richTextEditor = () => {
    // The Tiptap editor lives outside Alpine's reactive state: wrapped in
    // Alpine's proxy, every toolbar command failed with "Applying a mismatched
    // transaction".
    let editor = null

    return {
        content: '',

        // Tiptap is most of the bundle but only the submission wizard uses it, so it
        // is loaded on demand into its own chunk (RV-PERF-19).
        async init() {
            // Initial HTML comes from data-initial-content (see alpine-components.js).
            this.content = this.$el.dataset.initialContent || ''
            const [{ Editor }, { default: StarterKit }] = await Promise.all([
                import('@tiptap/core'),
                import('@tiptap/starter-kit'),
            ])

            editor = new Editor({
                element: this.$refs.editor,
                extensions: [StarterKit],
                content: this.content,
                editorProps: {
                    attributes: {
                        class: 'prose prose-sm dark:prose-invert min-h-48 max-w-none px-3 py-2 focus:outline-none',
                        'aria-label': 'Body',
                    },
                },
                onUpdate: ({ editor: current }) => {
                    this.content = current.isEmpty ? '' : current.getHTML()
                },
            })

            this.$watch('content', (content) => {
                if (editor && content !== editor.getHTML()) {
                    editor.commands.setContent(content || '', { emitUpdate: false })
                }
            })
        },

        command(name) {
            if (! editor) {
                return
            }

            const chain = editor.chain().focus()

            const commands = {
                paragraph: () => chain.setParagraph(),
                heading2: () => chain.toggleHeading({ level: 2 }),
                heading3: () => chain.toggleHeading({ level: 3 }),
                bold: () => chain.toggleBold(),
                italic: () => chain.toggleItalic(),
                strike: () => chain.toggleStrike(),
                bulletList: () => chain.toggleBulletList(),
                orderedList: () => chain.toggleOrderedList(),
                blockquote: () => chain.toggleBlockquote(),
                codeBlock: () => chain.toggleCodeBlock(),
                undo: () => chain.undo(),
                redo: () => chain.redo(),
            }

            if (commands[name]) {
                commands[name]().run()
            }
        },

        destroy() {
            if (editor) {
                editor.destroy()
            }
        },
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('requestTypeBuilderDraft', requestTypeBuilderDraft)
    window.Alpine.data('richTextEditor', richTextEditor)
})
