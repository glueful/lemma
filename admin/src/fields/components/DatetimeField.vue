<script setup lang="ts">
import { computed } from 'vue'
import { parseDate, type DateValue } from '@internationalized/date'
import type { FieldDef } from '../types'

defineProps<{ field: FieldDef }>()
// The backend stores an ISO string; UInputDate works with a DateValue (@internationalized/date),
// so we bridge the two. The date portion (YYYY-MM-DD) is authoritative for Phase 1.
const model = defineModel<string>()

const dateValue = computed<DateValue | undefined>({
  get() {
    const v = model.value
    if (!v) return undefined
    try {
      return parseDate(v.slice(0, 10))
    } catch {
      return undefined
    }
  },
  set(d) {
    model.value = d ? d.toString() : undefined
  },
})
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UInputDate v-model="dateValue" />
  </UFormField>
</template>
