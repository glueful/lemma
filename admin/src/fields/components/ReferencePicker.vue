<script setup lang="ts">
// Searchable entry picker for a `reference` field with a known target content type. Server-side
// search via the entries query (debounced); binds the selected entry's UUID.
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useEntries } from '@/queries/entries'

const props = defineProps<{ target: string }>()
const model = defineModel<string>()

const searchTerm = ref('')
const debounced = refDebounced(searchTerm, 250)

const { data } = useEntries(
  () => props.target,
  () => 1,
  () => 20,
  () => debounced.value || undefined,
)

const items = computed(() =>
  (data.value?.entries ?? []).map((e) => ({ label: e.display_title || e.uuid, value: e.uuid })),
)
</script>

<template>
  <USelectMenu
    v-model="model"
    :items="items"
    value-key="value"
    placeholder="Choose an entry…"
    class="w-full"
    @update:search-term="searchTerm = $event"
  />
</template>
