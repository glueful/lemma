<script setup lang="ts">
import { useCollections, type Collection } from '@/queries/collections'

defineProps<{ selectedName?: string }>()
const emit = defineEmits<{ select: [collection: Collection] }>()

const { data, status } = useCollections()
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col lg:w-72">
    <div class="pb-3">
      <h2 class="mb-2 text-base font-semibold text-default">Collections</h2>
      <UButton
        block
        size="sm"
        icon="i-lucide-plus"
        data-test="new-collection"
        to="/collections/new"
      >
        New collection
      </UButton>
    </div>

    <div v-if="status === 'pending'" class="text-sm text-muted">Loading…</div>
    <div v-else-if="(data ?? []).length === 0" class="text-sm text-muted">No collections yet.</div>
    <div v-else class="min-h-0 flex-1 space-y-1 overflow-auto">
      <button
        v-for="c in data ?? []"
        :key="c.name"
        type="button"
        data-test="collection-row"
        class="flex w-full items-center justify-between gap-2 rounded-md px-3 py-2 text-left hover:bg-elevated"
        :class="c.name === selectedName ? 'bg-elevated' : ''"
        @click="emit('select', c)"
      >
        <span class="min-w-0">
          <span class="block truncate text-sm font-medium text-default">{{ c.name }}</span>
          <span class="block truncate text-xs text-muted">{{ c.label }}</span>
        </span>
        <UBadge color="neutral" variant="subtle" size="xs">{{ c.fields.length }}</UBadge>
      </button>
    </div>
  </div>
</template>
