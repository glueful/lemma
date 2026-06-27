<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import { useEntries, useCreateEntry, type EntryListRow } from '@/queries/entries'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const type = computed(() => String(route.params.type))

const { error: notifyError } = useNotify()
const { mutateAsync: createEntry, isLoading: creating } = useCreateEntry()

async function onCreate() {
  try {
    const uuid = await createEntry(type.value)
    if (uuid) router.push(`/content/${type.value}/${uuid}`)
  } catch (e) {
    notifyError(e, 'Could not create entry')
  }
}

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
  <UDashboardPanel id="content-entries">
    <template #header>
      <UDashboardNavbar>
        <template #title
          ><span class="capitalize">{{ type }}</span></template
        >
        <template #right>
          <UInput v-model="search" icon="i-lucide-search" placeholder="Search…" class="w-64" />
          <UButton
            variant="subtle"
            color="neutral"
            icon="i-lucide-signpost"
            :to="`/content/${type}/redirects`"
          >
            Redirects
          </UButton>
          <UButton icon="i-lucide-plus" class="capitalize" :loading="creating" @click="onCreate">
            New {{ type }}
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
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

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="entries"
      />
    </template>
  </UDashboardPanel>
</template>
