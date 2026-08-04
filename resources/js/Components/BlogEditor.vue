<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import Link from '@tiptap/extension-link'
import Image from '@tiptap/extension-image'
import Placeholder from '@tiptap/extension-placeholder'
import { Bold, Heading2, Heading3, Image as ImageIcon, Italic, Link as LinkIcon, List, ListOrdered, Quote } from '@lucide/vue'

const props = defineProps<{
    modelValue: string
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: string): void
}>()

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Link.configure({
            openOnClick: false,
            autolink: true,
            HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' },
        }),
        Image,
        Placeholder.configure({
            placeholder: 'Write your article…',
        }),
    ],
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML())
    },
})

watch(
    () => props.modelValue,
    (value) => {
        if (editor.value && value !== editor.value.getHTML()) {
            editor.value.commands.setContent(value)
        }
    },
)

function toggleLink(): void {
    if (!editor.value) return

    const { state } = editor.value
    if (state.selection.empty) {
        const url = window.prompt('Link URL', 'https://')
        if (url) {
            editor.value.chain().focus().setLink({ href: url }).run()
        }
        return
    }

    const linkMark = state.storedMarks?.find((mark) => mark.type.name === 'link')
    if (linkMark) {
        editor.value.chain().focus().unsetLink().run()
    } else {
        const url = window.prompt('Link URL', 'https://')
        if (url) {
            editor.value.chain().focus().setLink({ href: url }).run()
        }
    }
}

function addImage(): void {
    const url = window.prompt('Image URL')
    if (url && editor.value) {
        editor.value.chain().focus().setImage({ src: url }).run()
    }
}

function isActive(name: string, attrs?: Record<string, unknown>): boolean {
    return editor.value?.isActive(name, attrs) ?? false
}

onBeforeUnmount(() => {
    editor.value?.destroy()
})
</script>

<template>
    <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
        <div class="flex flex-wrap items-center gap-1 border-b border-neutral-200 bg-neutral-50 px-2 py-1.5 dark:border-neutral-700 dark:bg-neutral-800">
            <button
                type="button"
                title="Bold"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('bold') }"
                @mousedown.prevent="editor?.chain().focus().toggleBold().run()"
            >
                <Bold class="h-4 w-4" />
            </button>
            <button
                type="button"
                title="Italic"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('italic') }"
                @mousedown.prevent="editor?.chain().focus().toggleItalic().run()"
            >
                <Italic class="h-4 w-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-neutral-300 dark:bg-neutral-600" />
            <button
                type="button"
                title="Heading 2"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('heading', { level: 2 }) }"
                @mousedown.prevent="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
            >
                <Heading2 class="h-4 w-4" />
            </button>
            <button
                type="button"
                title="Heading 3"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('heading', { level: 3 }) }"
                @mousedown.prevent="editor?.chain().focus().toggleHeading({ level: 3 }).run()"
            >
                <Heading3 class="h-4 w-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-neutral-300 dark:bg-neutral-600" />
            <button
                type="button"
                title="Bullet list"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('bulletList') }"
                @mousedown.prevent="editor?.chain().focus().toggleBulletList().run()"
            >
                <List class="h-4 w-4" />
            </button>
            <button
                type="button"
                title="Numbered list"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('orderedList') }"
                @mousedown.prevent="editor?.chain().focus().toggleOrderedList().run()"
            >
                <ListOrdered class="h-4 w-4" />
            </button>
            <button
                type="button"
                title="Blockquote"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('blockquote') }"
                @mousedown.prevent="editor?.chain().focus().toggleBlockquote().run()"
            >
                <Quote class="h-4 w-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-neutral-300 dark:bg-neutral-600" />
            <button
                type="button"
                title="Link"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                :class="{ 'bg-neutral-200 dark:bg-neutral-700': isActive('link') }"
                @mousedown.prevent="toggleLink"
            >
                <LinkIcon class="h-4 w-4" />
            </button>
            <button
                type="button"
                title="Image"
                class="rounded p-1.5 hover:bg-neutral-200 disabled:opacity-40 dark:hover:bg-neutral-700"
                @mousedown.prevent="addImage"
            >
                <ImageIcon class="h-4 w-4" />
            </button>
        </div>

        <EditorContent
            :editor="editor"
            class="prose prose-neutral max-w-none p-3 text-sm focus:outline-none dark:prose-invert [&_.tiptap]:min-h-[300px] [&_.tiptap]:outline-none [&_p.is-editor-empty:first-child::before]:content-[attr(data-placeholder)]"
        />
    </div>
</template>
