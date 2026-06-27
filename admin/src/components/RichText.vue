<script setup lang="ts">
// Reusable rich-text editor (Nuxt UI's UEditor). The model is an HTML string (content-type="html"),
// matching the backend `text` column contract. A fixed toolbar sits at the top of the editor; a
// bubble toolbar appears on text selection; links use a popover (RichTextLink). Beyond the starter
// kit we add task lists, tables, and blob-backed image upload. Mentions are off (no mention source
// in the admin). The editor is document-style: it grows with its content rather than scrolling in a
// fixed box.
import { useTemplateRef } from 'vue'
import type { EditorToolbarItem, EditorCustomHandlers } from '@nuxt/ui'
import { TaskList, TaskItem } from '@tiptap/extension-list'
import RichTextLink from '@/components/RichTextLink.vue'
import { useUploadMedia } from '@/queries/media'
import { useNotify } from '@/composables/useNotify'

withDefaults(
  defineProps<{
    placeholder?: string
    editable?: boolean
  }>(),
  { editable: true },
)

const model = defineModel<string>()

// Derive the Tiptap Editor type from @nuxt/ui's handler signature — @tiptap/* core types aren't a
// direct/hoisted dependency, so we can't name them here.
type Editor = Parameters<EditorCustomHandlers[string]['execute']>[0]

// Extensions added on top of UEditor's starter kit (which already registers the image node).
const extensions = [TaskList, TaskItem]

// ── Image upload (blob-backed) ──────────────────────────────────────────────
const { error: notifyError } = useNotify()
const { mutateAsync: uploadMedia, isLoading: uploadingImage } = useUploadMedia()
const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
// The slot-scoped editor isn't reachable from the file <input>'s change handler, so stash it
// between the toolbar click and the upload completing.
let pendingEditor: Editor | null = null

function pickImage(editor: Editor) {
  pendingEditor = editor
  fileInput.value?.click()
}

async function onImageSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  const editor = pendingEditor
  pendingEditor = null
  if (!file || !editor) return
  try {
    // Content images are public so they render in delivery without signed URLs.
    const asset = await uploadMedia({ file, visibility: 'public' })
    if (asset.url) editor.chain().focus().setImage({ src: asset.url }).run()
  } catch (e) {
    notifyError(e, 'Couldn’t upload image')
  }
}

// "Turn into" block-type menu, shared by the fixed and bubble toolbars. `satisfies` keeps the string
// literals (color/variant/kind/…) narrow so it stays assignable to EditorToolbarItem when reused.
const turnInto = {
  label: 'Turn into',
  trailingIcon: 'i-lucide-chevron-down',
  color: 'neutral',
  variant: 'ghost',
  content: { align: 'start' },
  ui: { label: 'text-xs' },
  items: [
    { type: 'label', label: 'Turn into' },
    { kind: 'paragraph', label: 'Paragraph', icon: 'i-lucide-type' },
    { kind: 'heading', level: 1, label: 'Heading 1', icon: 'i-lucide-heading-1' },
    { kind: 'heading', level: 2, label: 'Heading 2', icon: 'i-lucide-heading-2' },
    { kind: 'heading', level: 3, label: 'Heading 3', icon: 'i-lucide-heading-3' },
    { kind: 'bulletList', label: 'Bullet list', icon: 'i-lucide-list' },
    { kind: 'orderedList', label: 'Ordered list', icon: 'i-lucide-list-ordered' },
    { kind: 'taskList', label: 'Task list', icon: 'i-lucide-list-checks' },
    { kind: 'blockquote', label: 'Blockquote', icon: 'i-lucide-text-quote' },
    { kind: 'codeBlock', label: 'Code block', icon: 'i-lucide-square-code' },
  ],
} satisfies EditorToolbarItem

// Fixed toolbar — always visible at the top of the editor.
const toolbarItems = [
  [
    { kind: 'undo', icon: 'i-lucide-undo-2' },
    { kind: 'redo', icon: 'i-lucide-redo-2' },
  ],
  [turnInto],
  [
    { kind: 'mark', mark: 'bold', icon: 'i-lucide-bold' },
    { kind: 'mark', mark: 'italic', icon: 'i-lucide-italic' },
    { kind: 'mark', mark: 'underline', icon: 'i-lucide-underline' },
    { kind: 'mark', mark: 'strike', icon: 'i-lucide-strikethrough' },
    { kind: 'mark', mark: 'code', icon: 'i-lucide-code' },
  ],
  [
    { kind: 'bulletList', icon: 'i-lucide-list' },
    { kind: 'orderedList', icon: 'i-lucide-list-ordered' },
    { kind: 'taskList', icon: 'i-lucide-list-checks' },
    { kind: 'blockquote', icon: 'i-lucide-quote' },
  ],
  [
    { slot: 'link', icon: 'i-lucide-link' },
    { slot: 'image', icon: 'i-lucide-image' },
    { kind: 'horizontalRule', icon: 'i-lucide-minus' },
  ],
] satisfies EditorToolbarItem[][]

// Bubble toolbar — appears over a non-empty text selection (Nuxt UI's default shouldShow).
const bubbleItems = [
  [turnInto],
  [
    { kind: 'mark', mark: 'bold', icon: 'i-lucide-bold' },
    { kind: 'mark', mark: 'italic', icon: 'i-lucide-italic' },
    { kind: 'mark', mark: 'underline', icon: 'i-lucide-underline' },
    { kind: 'mark', mark: 'strike', icon: 'i-lucide-strikethrough' },
    { kind: 'mark', mark: 'code', icon: 'i-lucide-code' },
  ],
  [{ slot: 'link', icon: 'i-lucide-link' }],
] satisfies EditorToolbarItem[][]
</script>

<template>
  <UEditor
    v-slot="{ editor }"
    v-model="model"
    content-type="html"
    :mention="false"
    :editable="editable"
    :placeholder="placeholder"
    :extensions="extensions"
    :ui="{ base: 'py-3 outline-none min-h-64' }"
    class="w-full"
  >
    <UEditorToolbar
      v-if="editable"
      :editor="editor"
      :items="toolbarItems"
      class="mb-1 flex flex-wrap items-center gap-0.5 border-b border-default pb-1.5"
    >
      <template #link>
        <RichTextLink :editor="editor" />
      </template>
      <template #image>
        <UTooltip text="Insert image">
          <UButton
            icon="i-lucide-image"
            color="neutral"
            variant="ghost"
            size="sm"
            :loading="uploadingImage"
            aria-label="Insert image"
            @click="pickImage(editor)"
          />
        </UTooltip>
      </template>
    </UEditorToolbar>

    <UEditorToolbar v-if="editable" :editor="editor" :items="bubbleItems" layout="bubble">
      <template #link>
        <RichTextLink :editor="editor" />
      </template>
    </UEditorToolbar>
  </UEditor>

  <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="onImageSelected" />
</template>

<style scoped>
/* The editor theme styles links/lists/code/images but not task lists — add those here. */
:deep(.ProseMirror ul[data-type='taskList']) {
  padding-left: 0;
  list-style: none;
}
:deep(.ProseMirror ul[data-type='taskList'] li) {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
}
:deep(.ProseMirror ul[data-type='taskList'] li > label) {
  margin-top: 0.2rem;
  user-select: none;
}
:deep(.ProseMirror ul[data-type='taskList'] li > div) {
  flex: 1 1 auto;
  min-width: 0;
}
</style>
