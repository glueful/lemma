<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// Vue 3.5 defineModel: two-way binding with no manual props/emit plumbing.
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UInput v-model="model" class="w-full" />
  </UFormField>
</template>
