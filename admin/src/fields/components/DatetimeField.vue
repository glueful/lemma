<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UInput v-model="model" type="datetime-local" class="w-full" />
  </UFormField>
</template>
