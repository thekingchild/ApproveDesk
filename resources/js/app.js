import './pwa.js'

// Form builder local-draft autosave (P6B-UI-04): stores builder state in
// this browser only, debounced; the server draft stays the durable record.
window.requestTypeBuilderDraft = ({ key, snapshot, restore, discardFlag, onReady }) => ({
    savedAt: '',
    timer: null,

    init() {
        const stored = localStorage.getItem(key)

        if (stored) {
            try {
                const parsed = JSON.parse(stored)

                if (parsed && parsed.payload) {
                    window.__builderDraftPending = parsed
                    this.savedAt = parsed.savedAt || ''
                    this.$wire.localDraftSavedAt = parsed.savedAt || ''
                    this.$wire.localDraftAvailable = true
                }
            } catch {
                localStorage.removeItem(key)
            }
        }

        onReady?.(this)

        // Debounced autosave on any builder change (name or fields).
        this.$wire.$watch('fields', () => this.saveDraft())
        this.$wire.$watch('name', () => this.saveDraft())
    },

    saveDraft() {
        clearTimeout(this.timer)
        this.timer = setTimeout(() => {
            const payload = { savedAt: new Date().toLocaleTimeString(), payload: snapshot() }
            localStorage.setItem(key, JSON.stringify(payload))
            this.savedAt = payload.savedAt
        }, 800)
    },

    restoreDraft() {
        const stored = localStorage.getItem(key)

        if (! stored) {
            return
        }

        try {
            const parsed = JSON.parse(stored)
            parsed.payload && restore(parsed.payload)
            localStorage.removeItem(key)
            this.savedAt = ''
        } catch {
            localStorage.removeItem(key)
        }
    },

    discardDraft() {
        localStorage.removeItem(key)
        discardFlag()
        this.savedAt = ''
    },
})

window.richTextEditor = (initialContent = '') => ({
    content: initialContent || '',
    editor: null,

    // Tiptap is most of the bundle but only the submission wizard uses it, so it
    // is loaded on demand into its own chunk (RV-PERF-19).
    async init() {
        const [{ Editor }, { default: StarterKit }] = await Promise.all([
            import('@tiptap/core'),
            import('@tiptap/starter-kit'),
        ])

        this.editor = new Editor({
            element: this.$refs.editor,
            extensions: [StarterKit],
            content: this.content,
            editorProps: {
                attributes: {
                    class: 'prose prose-sm dark:prose-invert min-h-48 max-w-none px-3 py-2 focus:outline-none',
                    'aria-label': 'Body',
                },
            },
            onUpdate: ({ editor }) => {
                this.content = editor.isEmpty ? '' : editor.getHTML()
            },
        })

        this.$watch('content', (content) => {
            if (this.editor && content !== this.editor.getHTML()) {
                this.editor.commands.setContent(content || '', { emitUpdate: false })
            }
        })
    },

    command(name, options = {}) {
        if (! this.editor) {
            return
        }

        const chain = this.editor.chain().focus()

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

        commands[name]?.(options).run()
    },

    destroy() {
        this.editor?.destroy()
    },
})
