<script setup lang="ts">
import { ref, watch } from 'vue'
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// Stored as a raw JSON string; we surface a parse error on blur but never block typing.
const model = defineModel<string>()
const error = ref<string | null>(null)

watch(model, (v) => {
  if (!v) {
    error.value = null
    return
  }
  try {
    JSON.parse(v)
    error.value = null
  } catch {
    error.value = 'Invalid JSON.'
  }
})
</script>

<template>
  <UFormField
    :label="field.name"
    :required="field.required"
    :name="field.name"
    :error="error ?? undefined"
  >
    <UTextarea v-model="model" :rows="4" class="w-full font-mono text-sm" />
  </UFormField>
</template>
