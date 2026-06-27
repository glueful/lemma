<script setup lang="ts">
import { computed } from 'vue'
import type { FieldDef } from '../types'
import ReferencePicker from './ReferencePicker.vue'

const props = defineProps<{ field: FieldDef }>()
// The reference value is the target entry's UUID either way.
const model = defineModel<string>()
// A searchable picker needs the reference's target content type. When the schema declares one we
// render the picker; otherwise we fall back to the honest UUID input.
const target = computed(() => props.field.referenceType ?? '')
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <ReferencePicker v-if="target" v-model="model" :target="target" />
    <UInput v-else v-model="model" placeholder="Entry UUID" class="w-full" />
  </UFormField>
</template>
