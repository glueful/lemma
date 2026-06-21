<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import { useEntries, type EntryListRow } from '@/queries/entries'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const type = computed(() => String(route.params.type))

const page = ref(1)
const perPage = ref(20)
const search = ref('')
const debouncedSearch = refDebounced(search, 300)

const { data, status } = useEntries(type, page, perPage, debouncedSearch)

const columns: TableColumn<EntryListRow>[] = [
  { accessorKey: 'display_title', header: 'Title' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'locales', header: 'Locales' },
  { accessorKey: 'updated_at', header: 'Updated' },
]

function statusColor(s: string): 'success' | 'warning' | 'neutral' {
  if (s === 'published') return 'success'
  if (s === 'scheduled') return 'warning'
  return 'neutral'
}
</script>

<template>
  <div class="space-y-4 p-6">
    <header class="flex items-center justify-between gap-4">
      <h1 class="text-xl font-semibold text-highlighted capitalize">{{ type }}</h1>
      <div class="flex items-center gap-2">
        <UInput v-model="search" icon="i-lucide-search" placeholder="Search…" class="w-64" />
        <UButton
          variant="subtle"
          color="neutral"
          icon="i-lucide-signpost"
          :to="`/content/${type}/redirects`"
        >
          Redirects
        </UButton>
      </div>
    </header>

    <UTable :data="data?.entries ?? []" :columns="columns" :loading="status === 'pending'">
      <template #display_title-cell="{ row }">
        <ULink :to="`/content/${type}/${row.original.uuid}`" class="font-medium text-default">
          {{ row.original.display_title }}
        </ULink>
      </template>

      <template #status-cell="{ row }">
        <UBadge :color="statusColor(row.original.status)" variant="subtle">
          {{ row.original.status }}
        </UBadge>
      </template>

      <template #locales-cell="{ row }">
        <div class="flex gap-1">
          <UBadge
            v-for="loc in row.original.locales"
            :key="loc"
            color="neutral"
            variant="outline"
            size="sm"
          >
            {{ loc }}
          </UBadge>
        </div>
      </template>

      <template #updated_at-cell="{ row }">
        <span class="text-sm text-muted">{{ row.original.updated_at ?? '—' }}</span>
      </template>
    </UTable>

    <div v-if="(data?.total ?? 0) > perPage" class="flex justify-end">
      <UPagination v-model:page="page" :total="data?.total ?? 0" :items-per-page="perPage" />
    </div>
  </div>
</template>
