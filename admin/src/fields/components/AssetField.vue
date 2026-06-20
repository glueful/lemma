<script setup lang="ts">
import { ref } from 'vue'
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// Stores the uploaded asset reference (a string). Task F6 wires the selected file through the
// media upload mutation and sets `model` to the returned reference.
const model = defineModel<string>()
const file = ref<File | null>(null)
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UFileUpload v-model="file" />
    <p v-if="model" class="mt-1 text-xs text-muted">Current: {{ model }}</p>
  </UFormField>
</template>
