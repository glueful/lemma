<script setup lang="ts">
import { computed } from 'vue'
import { useCollections, type CollectionFieldType } from '@/queries/collections'

defineProps<{ type: CollectionFieldType; disabled?: boolean; indexEditable?: boolean }>()

// The settings object is owned by the parent field row; we mutate its keys in place.
const settings = defineModel<Record<string, unknown>>('settings', { required: true })

// Relation targets are the other (existing) collections. The resolver keys off a
// `collection:<name>` target (see RelationResolver), so that prefix is part of the stored value.
const { data: collections } = useCollections()
const collectionItems = computed(() => [
  // The framework users table is a first-class relation target (resolver exposes safe columns only).
  { label: 'Users (system)', value: 'users' },
  ...(collections.value ?? []).map((c) => ({
    label: c.label || c.name,
    value: `collection:${c.name}`,
  })),
])

function toNum(v: unknown): number | undefined {
  const n = Number(v)
  return Number.isFinite(n) ? n : undefined
}

// `nullable` defaults to true on the backend, so Required is its inverse.
const required = computed({
  get: () => settings.value.nullable === false,
  set: (v) => {
    settings.value.nullable = !v
  },
})
const unique = computed({
  get: () => settings.value.unique === true,
  set: (v) => {
    settings.value.unique = v
  },
})
const indexed = computed({
  get: () => settings.value.index === true,
  set: (v) => {
    settings.value.index = v
  },
})
const bigint = computed({
  get: () => settings.value.bigint === true,
  set: (v) => {
    settings.value.bigint = v
  },
})
const multi = computed({
  get: () => settings.value.multi === true,
  set: (v) => {
    settings.value.multi = v
  },
})
const length = computed({
  get: () => (typeof settings.value.length === 'number' ? settings.value.length : 255),
  set: (v) => {
    settings.value.length = toNum(v)
  },
})
const precision = computed({
  get: () => (typeof settings.value.precision === 'number' ? settings.value.precision : 10),
  set: (v) => {
    settings.value.precision = toNum(v)
  },
})
const scale = computed({
  get: () => (typeof settings.value.scale === 'number' ? settings.value.scale : 2),
  set: (v) => {
    settings.value.scale = toNum(v)
  },
})
const target = computed({
  get: () => (typeof settings.value.target === 'string' ? settings.value.target : undefined),
  set: (v) => {
    settings.value.target = v
  },
})
const valuesText = computed({
  get: () => (Array.isArray(settings.value.values) ? settings.value.values.join(', ') : ''),
  set: (v) => {
    settings.value.values = v
      .split(',')
      .map((s) => s.trim())
      .filter(Boolean)
  },
})
</script>

<template>
  <div class="space-y-3 rounded-md border border-default bg-elevated/40 p-3">
    <div class="flex items-center gap-4">
      <USwitch v-model="required" label="Required" :disabled="disabled" />
      <USwitch v-model="unique" label="Unique" :disabled="disabled && !indexEditable" />
      <USwitch v-model="indexed" label="Index" :disabled="disabled && !indexEditable" />
      <USwitch
        v-if="type === 'collections.integer'"
        v-model="bigint"
        label="Big integer (64-bit)"
        :disabled="disabled"
      />
    </div>

    <p v-if="indexEditable" class="text-xs text-muted">
      Index / Unique can be changed on an existing field. Enabling Unique rebuilds the index and
      fails if the column's values aren't already unique.
    </p>

    <UFormField v-if="type === 'collections.string'" label="Max length" class="max-w-40">
      <UInput v-model="length" type="number" min="1" class="w-full" :disabled="disabled" />
    </UFormField>

    <div v-else-if="type === 'collections.decimal'" class="grid max-w-xs grid-cols-2 gap-3">
      <UFormField label="Precision">
        <UInput v-model="precision" type="number" min="1" class="w-full" :disabled="disabled" />
      </UFormField>
      <UFormField label="Scale">
        <UInput v-model="scale" type="number" min="0" class="w-full" :disabled="disabled" />
      </UFormField>
    </div>

    <div
      v-else-if="type === 'collections.relation' || type === 'collections.asset'"
      class="space-y-3"
    >
      <UFormField
        v-if="type === 'collections.relation'"
        label="Relates to"
        help="Stores the related row's uuid (a collection row or a user)."
      >
        <USelect
          v-model="target"
          :items="collectionItems"
          placeholder="Pick a target"
          class="w-full"
          :disabled="disabled"
        />
      </UFormField>
      <USwitch v-model="multi" label="Allow many (stores a uuid array)" :disabled="disabled" />
    </div>

    <UFormField
      v-else-if="type === 'collections.enum'"
      label="Allowed values"
      help="Comma-separated"
    >
      <UInput
        v-model="valuesText"
        placeholder="draft, published, archived"
        class="w-full"
        :disabled="disabled"
      />
    </UFormField>
  </div>
</template>
