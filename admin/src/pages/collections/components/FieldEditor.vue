<script setup lang="ts">
import { ref } from 'vue'
import {
  COLLECTION_FIELD_TYPES,
  COLLECTION_FIELD_TYPE_META,
  type CollectionFieldType,
} from '@/queries/collections'
import FieldCard from './FieldCard.vue'

const emit = defineEmits<{
  add: [field: { name: string; type: string; settings: Record<string, unknown> }]
}>()

interface Draft {
  name: string
  type: CollectionFieldType
  settings: Record<string, unknown>
  open: boolean
}
const draft = ref<Draft>({ name: '', type: 'collections.text', settings: {}, open: true })
const showDraft = ref(false)

const addItems = COLLECTION_FIELD_TYPES.map((t) => ({
  label: COLLECTION_FIELD_TYPE_META[t].label,
  icon: COLLECTION_FIELD_TYPE_META[t].icon,
  onSelect: () => startDraft(t),
}))

function startDraft(type: CollectionFieldType) {
  draft.value = { name: '', type, settings: {}, open: true }
  showDraft.value = true
}
function save() {
  const name = draft.value.name.trim()
  if (name === '') return
  emit('add', { name, type: draft.value.type, settings: { ...draft.value.settings } })
  showDraft.value = false
}
function cancel() {
  showDraft.value = false
}
</script>

<template>
  <div data-test="field-editor" class="space-y-3">
    <div class="flex justify-end">
      <UDropdownMenu :items="addItems" :content="{ align: 'end' }">
        <UButton variant="soft" color="neutral" icon="i-lucide-plus" data-test="add-field">
          Add field
        </UButton>
      </UDropdownMenu>
    </div>

    <FieldCard v-if="showDraft" v-model="draft" draft @save="save" @cancel="cancel" />
  </div>
</template>
