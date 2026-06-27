<script setup lang="ts">
// Reusable rich-text editor (Nuxt UI's UEditor). The model is an HTML string (content-type="html"),
// matching the backend `text` column contract. A fixed toolbar sits at the top of the editor; a
// bubble toolbar appears on text selection; links use a popover (RichTextLink) instead of the
// built-in native prompt. Mentions are off (no mention source in the admin). The editor is
// document-style: it grows with its content rather than scrolling inside a fixed box.
import type { EditorToolbarItem } from '@nuxt/ui'
import RichTextLink from '@/components/RichTextLink.vue'

withDefaults(
  defineProps<{
    placeholder?: string
    editable?: boolean
  }>(),
  { editable: true },
)

const model = defineModel<string>()

// Fixed toolbar — always visible at the top of the editor.
const toolbarItems = [
  [
    { kind: 'undo', icon: 'i-lucide-undo-2' },
    { kind: 'redo', icon: 'i-lucide-redo-2' },
  ],
  [
    {
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
        { kind: 'blockquote', label: 'Blockquote', icon: 'i-lucide-text-quote' },
        { kind: 'codeBlock', label: 'Code block', icon: 'i-lucide-square-code' },
      ],
    },
  ],
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
    { kind: 'blockquote', icon: 'i-lucide-quote' },
  ],
  [
    { slot: 'link', icon: 'i-lucide-link' },
    { kind: 'horizontalRule', icon: 'i-lucide-minus' },
  ],
] satisfies EditorToolbarItem[][]

// Bubble toolbar — appears over a non-empty text selection (Nuxt UI's default shouldShow).
const bubbleItems = [
  [
    {
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
        { kind: 'blockquote', label: 'Blockquote', icon: 'i-lucide-text-quote' },
        { kind: 'codeBlock', label: 'Code block', icon: 'i-lucide-square-code' },
      ],
    },
  ],
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
    </UEditorToolbar>

    <UEditorToolbar v-if="editable" :editor="editor" :items="bubbleItems" layout="bubble">
      <template #link>
        <RichTextLink :editor="editor" />
      </template>
    </UEditorToolbar>
  </UEditor>
</template>
