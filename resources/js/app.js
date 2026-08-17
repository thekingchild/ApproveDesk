import { Editor } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'

window.richTextEditor = (initialContent = '') => ({
    content: initialContent || '',
    editor: null,

    init() {
        this.editor = new Editor({
            element: this.$refs.editor,
            extensions: [StarterKit],
            content: this.content,
            editorProps: {
                attributes: {
                    class: 'prose prose-sm dark:prose-invert min-h-48 max-w-none px-3 py-2 focus:outline-none',
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
