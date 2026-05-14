<script setup lang="ts">
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { watch, onBeforeUnmount, onMounted } from 'vue'

const props = defineProps<{ modelValue: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()

const editor = useEditor({
    extensions: [StarterKit],
    content: props.modelValue ?? '',
    onUpdate: ({ editor }) => {
        emit('update:modelValue', editor.getHTML())
    },
    editorProps: {
        attributes: {
            class: 'prose prose-sm max-w-none min-h-[120px] p-3 focus:outline-none',
        },
    },
})

// Sync external changes into editor (e.g. locale switch or form reset)
watch(() => props.modelValue, (val) => {
    if (!editor.value) return
    const current = editor.value.getHTML()
    const incoming = val ?? ''
    if (current !== incoming) {
        editor.value.commands.setContent(incoming)
    }
})

onBeforeUnmount(() => editor.value?.destroy())

// RichTextEditor.vue — no onMounted
onMounted(() => {
    console.log('=== RichTextEditor MOUNTED ===')
    console.log('modelValue recebido:', props.modelValue)
    console.log('editor HTML:', editor.value?.getHTML())
})
</script>

<template>
    <div>
        <!-- Toolbar -->
        <div class="flex flex-wrap gap-1 border border-gray-300 border-b-0 rounded-t-lg bg-gray-50 px-2 py-1.5">
            <button
                type="button"
                @click="editor?.chain().focus().toggleBold().run()"
                class="px-2 py-1 rounded text-xs font-bold hover:bg-gray-200 transition-colors"
                :class="{ 'bg-gray-200': editor?.isActive('bold') }"
            >B</button>
            <button
                type="button"
                @click="editor?.chain().focus().toggleItalic().run()"
                class="px-2 py-1 rounded text-xs italic hover:bg-gray-200 transition-colors"
                :class="{ 'bg-gray-200': editor?.isActive('italic') }"
            >I</button>
            <button
                type="button"
                @click="editor?.chain().focus().toggleStrike().run()"
                class="px-2 py-1 rounded text-xs line-through hover:bg-gray-200 transition-colors"
                :class="{ 'bg-gray-200': editor?.isActive('strike') }"
            >S</button>
            <div class="w-px bg-gray-300 mx-1" />
            <button
                type="button"
                @click="editor?.chain().focus().toggleHeading({ level: 2 }).run()"
                class="px-2 py-1 rounded text-xs hover:bg-gray-200 transition-colors"
                :class="{ 'bg-gray-200': editor?.isActive('heading', { level: 2 }) }"
            >H2</button>
            <button
                type="button"
                @click="editor?.chain().focus().toggleHeading({ level: 3 }).run()"
                class="px-2 py-1 rounded text-xs hover:bg-gray-200 transition-colors"
                :class="{ 'bg-gray-200': editor?.isActive('heading', { level: 3 }) }"
            >H3</button>
            <div class="w-px bg-gray-300 mx-1" />
            <button
                type="button"
                @click="editor?.chain().focus().toggleBulletList().run()"
                class="px-2 py-1 rounded text-xs hover:bg-gray-200 transition-colors"
                :class="{ 'bg-gray-200': editor?.isActive('bulletList') }"
            >• List</button>
            <button
                type="button"
                @click="editor?.chain().focus().toggleOrderedList().run()"
                class="px-2 py-1 rounded text-xs hover:bg-gray-200 transition-colors"
                :class="{ 'bg-gray-200': editor?.isActive('orderedList') }"
            >1. List</button>
            <div class="w-px bg-gray-300 mx-1" />
            <button
                type="button"
                @click="editor?.chain().focus().setHorizontalRule().run()"
                class="px-2 py-1 rounded text-xs hover:bg-gray-200 transition-colors"
            >—</button>
            <button
                type="button"
                @click="editor?.chain().focus().undo().run()"
                class="px-2 py-1 rounded text-xs hover:bg-gray-200 transition-colors"
            >↩</button>
            <button
                type="button"
                @click="editor?.chain().focus().redo().run()"
                class="px-2 py-1 rounded text-xs hover:bg-gray-200 transition-colors"
            >↪</button>
        </div>

        <!-- Editor area -->
        <div class="border border-gray-300 rounded-b-lg bg-white focus-within:ring-2 focus-within:ring-indigo-500 focus-within:border-indigo-500">
            <EditorContent :editor="editor" />
        </div>
    </div>
</template>

<style scoped>
:deep(.ProseMirror) {
    min-height: 120px;
    padding: 0.75rem;
    font-size: 0.875rem;
    line-height: 1.5;
    outline: none;
}
:deep(.ProseMirror p) { margin: 0 0 0.5rem; }
:deep(.ProseMirror h2) { font-size: 1.1rem; font-weight: 600; margin: 0.75rem 0 0.25rem; }
:deep(.ProseMirror h3) { font-size: 1rem; font-weight: 600; margin: 0.5rem 0 0.25rem; }
:deep(.ProseMirror ul, .ProseMirror ol) { padding-left: 1.25rem; margin: 0.25rem 0; }
:deep(.ProseMirror li) { margin: 0.1rem 0; }
:deep(.ProseMirror p.is-editor-empty:first-child::before) {
    content: attr(data-placeholder);
    color: #9ca3af;
    pointer-events: none;
    float: left;
    height: 0;
}
</style>