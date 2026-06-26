<script setup lang="ts">
// Reusable rich-text editor (Nuxt UI's UEditor + a toolbar), wrapped so any page can drop in
// `<RichText v-model="html" />`. The model is an HTML string (content-type="html"), matching the
// backend `text` column contract. Mentions are off (no mention source in the admin).
import type { EditorToolbarItem } from '@nuxt/ui'

withDefaults(
  defineProps<{
    placeholder?: string
    editable?: boolean
  }>(),
  { editable: true },
)

const model = defineModel<string>()

const toolbarItems: EditorToolbarItem[][] = [
  [
    { kind: 'heading', level: 1, icon: 'i-lucide-heading-1' },
    { kind: 'heading', level: 2, icon: 'i-lucide-heading-2' },
    { kind: 'heading', level: 3, icon: 'i-lucide-heading-3' },
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
    { kind: 'link', icon: 'i-lucide-link' },
    { kind: 'horizontalRule', icon: 'i-lucide-minus' },
  ],
  [
    { kind: 'undo', icon: 'i-lucide-undo-2' },
    { kind: 'redo', icon: 'i-lucide-redo-2' },
  ],
]
</script>

<template>
  <UEditor
    v-slot="{ editor }"
    v-model="model"
    content-type="html"
    :mention="false"
    :editable="editable"
    :placeholder="placeholder"
    :ui="{ base: 'p-3 outline-none min-h-32' }"
    class="w-full max-h-128 overflow-y-auto rounded-md border border-default bg-default transition-colors focus-within:border-primary"
  >
    <UEditorToolbar
      :editor="editor"
      :items="toolbarItems"
      class="sticky top-0 z-10 flex flex-wrap gap-0.5 border-b border-default bg-elevated/50 px-2 py-1.5"
    />
  </UEditor>
</template>
