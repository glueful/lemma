<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// Long-form text. UTextarea stores a plain string (matching the backend `text` contract).
// Planned upgrade: swap to UEditor here for rich text — the registry makes it a one-file change.
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UTextarea v-model="model" :rows="6" autoresize class="w-full" />
  </UFormField>
</template>
