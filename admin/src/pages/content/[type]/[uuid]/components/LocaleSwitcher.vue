<script setup lang="ts">
import { computed } from 'vue'
import type { EntryLocaleSummary } from '@/queries/entries'
import type { Locale } from '@/queries/locales'
import { localeStatus } from './localeStatus'

const props = defineProps<{
  modelValue: string
  summaries: EntryLocaleSummary[]
  enabled: Locale[]
  addable: Locale[]
}>()
const emit = defineEmits<{ 'update:modelValue': [code: string]; create: [code: string] }>()

function label(code: string): string {
  const m = props.enabled.find((l) => l.code === code)
  return m ? `${m.name} (${code})` : code
}
const items = computed(() =>
  props.summaries.map((s) => {
    const st = localeStatus(s)
    return { label: label(s.locale), value: s.locale, icon: st.icon, status: st }
  }),
)
const current = computed(() => {
  const s = props.summaries.find((x) => x.locale === props.modelValue)
  return s ? localeStatus(s) : null
})

const addItems = computed(() =>
  props.addable.map((l) => ({
    label: `${l.name} (${l.code})`,
    onSelect: () => emit('create', l.code),
  })),
)

const selected = computed({
  get: () => props.modelValue,
  set: (v: string) => emit('update:modelValue', v),
})
</script>

<template>
  <div class="flex items-center gap-2">
    <USelectMenu
      v-model="selected"
      :items="items"
      value-key="value"
      :icon="current?.icon ?? 'i-lucide-languages'"
      :search-input="false"
      size="sm"
      class="w-52"
    >
      <template #item="{ item }">
        <span class="flex w-full items-center justify-between gap-2">
          <span class="truncate">{{ item.label }}</span>
          <UIcon :name="item.status.icon" :class="item.status.textClass" class="size-4 shrink-0" />
        </span>
      </template>
    </USelectMenu>

    <UBadge v-if="current" :color="current.color" variant="subtle" size="sm" :icon="current.icon">
      {{ current.label }}
    </UBadge>

    <UDropdownMenu v-if="addItems.length" :items="addItems" :content="{ align: 'end' }">
      <UButton
        icon="i-lucide-plus"
        color="neutral"
        variant="ghost"
        size="sm"
        aria-label="Add a locale version"
      />
    </UDropdownMenu>
  </div>
</template>
