<script setup lang="ts">
import { computed } from 'vue'

// Reusable footer for server-paginated tables: "Showing X–Y of N · Rows per page [select]" on the
// left, the page controls on the right. `page` and `perPage` are v-models so the parent owns the
// state (and its query reacts). Changing the page size resets to page 1.
const props = withDefaults(
  defineProps<{
    page: number
    perPage: number
    total: number
    label?: string
    pageSizes?: number[]
  }>(),
  {
    label: 'items',
    pageSizes: () => [10, 25, 50, 100],
  },
)

const emit = defineEmits<{
  'update:page': [value: number]
  'update:perPage': [value: number]
}>()

const from = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1))
const to = computed(() => Math.min(props.page * props.perPage, props.total))

const sizeItems = computed(() => props.pageSizes.map((n) => ({ label: String(n), value: n })))

function onPerPage(value: number) {
  emit('update:perPage', value)
  emit('update:page', 1)
}
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-3 pt-4">
    <div class="flex items-center gap-2 text-sm text-muted">
      <span>Showing {{ from }}–{{ to }} of {{ total }} {{ label }}</span>
      <span class="text-dimmed">·</span>
      <span>Rows per page</span>
      <USelect
        :model-value="perPage"
        :items="sizeItems"
        value-key="value"
        size="sm"
        class="w-20"
        @update:model-value="onPerPage"
      />
    </div>
    <UPagination
      :page="page"
      :total="total"
      :items-per-page="perPage"
      @update:page="(p: number) => emit('update:page', p)"
    />
  </div>
</template>
