<script setup lang="ts">
import { reactive } from 'vue'
import { COLLECTION_FIELD_TYPES, type CollectionFieldType } from '@/queries/collections'

const emit = defineEmits<{
  add: [field: { name: string; type: string; settings: Record<string, unknown> }]
}>()

const state = reactive<{ name: string; type: CollectionFieldType }>({
  name: '',
  type: 'collections.text',
})
const TYPE_ITEMS = COLLECTION_FIELD_TYPES.map((t) => ({ label: t.replace('collections.', ''), value: t }))

function add() {
  const name = state.name.trim()
  if (name === '') return
  emit('add', { name, type: state.type, settings: {} })
  state.name = ''
  state.type = 'collections.text'
}
</script>

<template>
  <div class="flex gap-2 items-center" data-test="field-editor">
    <UInput
      v-model="state.name"
      placeholder="field_name"
      class="flex-1"
      data-test="field-name"
      @keydown.enter.prevent="add"
    />
    <USelect v-model="state.type" :items="TYPE_ITEMS" class="w-48" />
    <UButton size="sm" icon="i-lucide-plus" data-test="add-field" @click="add">Add</UButton>
  </div>
</template>
