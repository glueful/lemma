<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// Phase 1: a plain entry-UUID reference. Planned upgrade: a searchable USelectMenu populated
// from a references query (the registry makes that a one-file swap).
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UInput v-model="model" placeholder="Entry UUID" class="w-full" />
  </UFormField>
</template>
