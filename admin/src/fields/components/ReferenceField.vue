<script setup lang="ts">
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// A plain entry-UUID reference. A searchable USelectMenu (the plan's intent) needs to know the
// reference's TARGET content type to list candidates — but the content-type field schema doesn't
// carry that yet (no target field on the definition). Until the schema/API exposes a target (or a
// references-candidates endpoint), a UUID input is the honest option. The registry makes the
// USelectMenu swap a one-file change once that data exists.
const model = defineModel<string>()
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UInput v-model="model" placeholder="Entry UUID" class="w-full" />
  </UFormField>
</template>
