<script setup lang="ts">
import { computed, ref, watchEffect } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useEntries } from '@/queries/entries'

const props = defineProps<{ target: string; maxItems?: number }>()
const model = defineModel<string[]>({ default: () => [] })

const searchTerm = ref('')
const debounced = refDebounced(searchTerm, 250)
const { data } = useEntries(
  () => props.target,
  () => 1,
  () => 20,
  () => debounced.value || undefined,
)

const titleCache = ref<Record<string, string>>({})
watchEffect(() => {
  for (const e of data.value?.entries ?? []) {
    titleCache.value[e.uuid] = e.display_title || e.uuid
  }
})

const atCap = computed(() => props.maxItems != null && model.value.length >= props.maxItems)

const items = computed(() =>
  (data.value?.entries ?? [])
    .filter((e) => !model.value.includes(e.uuid))
    .map((e) => ({ label: e.display_title || e.uuid, value: e.uuid })),
)

function add(uuid: string) {
  if (!uuid || model.value.includes(uuid) || atCap.value) return
  model.value = [...model.value, uuid]
}
function remove(uuid: string) {
  model.value = model.value.filter((u) => u !== uuid)
}

defineExpose({ add, remove })
</script>

<template>
  <div class="space-y-2">
    <div v-if="model.length" class="flex flex-wrap gap-1">
      <UBadge v-for="uuid in model" :key="uuid" color="neutral" variant="subtle" class="gap-1">
        {{ titleCache[uuid] ?? uuid }}
        <UButton
          icon="i-lucide-x"
          color="neutral"
          variant="ghost"
          size="xs"
          :aria-label="`Remove ${titleCache[uuid] ?? uuid}`"
          @click="remove(uuid)"
        />
      </UBadge>
    </div>
    <USelectMenu
      :items="items"
      value-key="value"
      :disabled="atCap"
      :placeholder="atCap ? `Max ${maxItems} reached` : 'Add an entry…'"
      class="w-full"
      @update:model-value="(v: string) => add(v)"
      @update:search-term="searchTerm = $event"
    />
  </div>
</template>
