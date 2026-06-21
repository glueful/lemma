<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// Rich text via UEditor. The model is an HTML string (tiptap Content accepts/emits a string),
// matching the backend `text` (string) contract.
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UEditor v-model="model" />
  </UFormField>
</template>
