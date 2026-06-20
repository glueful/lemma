<script setup lang="ts">
import type { FieldDef } from '@/fields/types'
import { fieldComponent } from '@/fields/registry'

defineProps<{ schema: FieldDef[] }>()
// The draft's field values, keyed by field name. We reassign (not mutate in place) on each field
// change so defineModel emits update:modelValue with the full record.
const model = defineModel<Record<string, unknown>>({ required: true })
</script>

<template>
  <div class="space-y-4">
    <component
      :is="fieldComponent(field.type)"
      v-for="field in schema"
      :key="field.name"
      :model-value="model[field.name]"
      :field="field"
      @update:model-value="(v: unknown) => (model = { ...model, [field.name]: v })"
    />
  </div>
</template>
