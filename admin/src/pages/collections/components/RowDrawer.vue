<script setup lang="ts">
import { reactive, watch } from 'vue'
import type { Collection, CollectionRow } from '@/queries/collections'

const props = defineProps<{
  open: boolean
  collection: Collection | null
  row?: CollectionRow | null
  loading?: boolean
}>()
const emit = defineEmits<{ save: [row: CollectionRow]; 'update:open': [value: boolean] }>()

// One string field per collection column; seeded from the row on edit, blank on create.
const state = reactive<Record<string, string>>({})

watch(
  () => [props.open, props.row, props.collection] as const,
  () => {
    if (!props.open || !props.collection) return
    for (const field of props.collection.fields) {
      const value = props.row ? props.row[field.name] : ''
      state[field.name] = value == null ? '' : String(value)
    }
  },
  { immediate: true },
)

const isEdit = () => props.row != null

function onSave() {
  const payload: CollectionRow = {}
  if (props.collection) {
    for (const field of props.collection.fields) payload[field.name] = state[field.name]
  }
  emit('save', payload)
}
</script>

<template>
  <USlideover
    :open="open"
    :title="isEdit() ? 'Edit row' : 'New row'"
    data-test="row-drawer"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="space-y-4">
        <UFormField
          v-for="field in collection?.fields ?? []"
          :key="field.name"
          :label="field.name"
          :help="field.type.replace('collections.', '')"
        >
          <UInput v-model="state[field.name]" :placeholder="field.name" />
        </UFormField>
        <p v-if="(collection?.fields ?? []).length === 0" class="text-sm text-muted">
          This collection has no fields yet.
        </p>
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="loading"
          @click="emit('update:open', false)"
        />
        <UButton :label="isEdit() ? 'Save' : 'Create'" :loading="loading" @click="onSave" />
      </div>
    </template>
  </USlideover>
</template>
