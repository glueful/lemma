<script setup lang="ts">
// Searchable picker for a collection `relation` field. Loads candidate rows from the target
// (`users` or `collection:<name>`) and binds the selected uuid (single) or uuid array (multi).
import { computed, ref, watch } from 'vue'
import type { CollectionField } from '@/queries/collections'
import { fetchRows } from '@/queries/collections'
import { fetchUsers, userDisplayName } from '@/queries/users'

const props = defineProps<{ field: CollectionField }>()
// The row state is loosely typed (unknown per column); proxy it into the shape USelectMenu wants.
const model = defineModel<unknown>()

const multiple = computed(() => props.field.settings.multi === true)
const required = computed(() => props.field.settings.nullable === false)

const selected = computed<string | string[] | undefined>({
  get: () => (model.value as string | string[] | undefined) ?? (multiple.value ? [] : undefined),
  set: (v) => {
    model.value = v
  },
})

const items = ref<{ label: string; value: string }[]>([])
const loading = ref(false)

// A readable label for a collection row: first non-system string value, else the short uuid.
function rowLabel(row: Record<string, unknown>): string {
  const uuid = String(row.uuid ?? '')
  for (const [k, v] of Object.entries(row)) {
    if (['id', 'uuid', 'created_at', 'updated_at'].includes(k)) continue
    if (typeof v === 'string' && v.trim() !== '') return `${v} · ${uuid.slice(0, 6)}`
  }
  return uuid
}

async function load() {
  const target = String(props.field.settings.target ?? '')
  loading.value = true
  try {
    if (target === 'users') {
      const page = await fetchUsers({ page: 1, perPage: 100 })
      items.value = page.users.map((u) => ({ label: userDisplayName(u), value: u.uuid }))
    } else if (target.startsWith('collection:')) {
      const name = target.slice('collection:'.length)
      const page = await fetchRows(name, { page: 1, perPage: 100 })
      items.value = page.rows.map((r) => ({ label: rowLabel(r), value: String(r.uuid ?? '') }))
    } else {
      items.value = []
    }
  } finally {
    loading.value = false
  }
}

watch(() => props.field.settings.target, load, { immediate: true })
</script>

<template>
  <UFormField :label="field.name" :required="required" :name="field.name">
    <USelectMenu
      v-model="selected"
      :items="items"
      value-key="value"
      :multiple="multiple"
      :loading="loading"
      :placeholder="multiple ? 'Select rows…' : 'Select a row…'"
      class="w-full"
    />
  </UFormField>
</template>
