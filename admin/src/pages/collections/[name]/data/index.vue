<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute } from 'vue-router'
import type { TableColumn } from '@nuxt/ui'
import {
  useCollection,
  useCollectionRows,
  useCollectionRowMutations,
  type CollectionRow,
} from '@/queries/collections'
import { useNotify } from '@/composables/useNotify'
import TablePagination from '@/components/TablePagination.vue'
import RowDrawer from '../../components/RowDrawer.vue'

const route = useRoute()
const { success, error: notifyError } = useNotify()

const name = computed(() => String(route.params.name))
const { data: collection } = useCollection(name)

const page = ref(1)
const perPage = ref(20)
const { data: pageData, status } = useCollectionRows(name, page, perPage)
const { create, update, remove } = useCollectionRowMutations(name)

const columns = computed<TableColumn<CollectionRow>[]>(() => [
  ...(collection.value?.fields ?? []).map((f) => ({ accessorKey: f.name, header: f.name })),
  { id: 'actions', header: '' },
])

const drawerOpen = ref(false)
const editingRow = ref<CollectionRow | null>(null)
function openCreate() {
  editingRow.value = null
  drawerOpen.value = true
}
function openEdit(row: CollectionRow) {
  editingRow.value = row
  drawerOpen.value = true
}

const saving = computed(() => create.isLoading.value || update.isLoading.value)
async function onSave(payload: CollectionRow) {
  try {
    const editing = editingRow.value
    if (editing && typeof editing.uuid === 'string') {
      await update.mutateAsync({ uuid: editing.uuid, row: payload })
      success('Row updated', 'Changes were saved.')
    } else {
      await create.mutateAsync(payload)
      success('Row created', 'The row was added.')
    }
    drawerOpen.value = false
  } catch (e) {
    notifyError(e, 'Couldn’t save row')
  }
}

const pendingDelete = ref<CollectionRow | null>(null)
async function confirmDelete() {
  const row = pendingDelete.value
  if (!row || typeof row.uuid !== 'string') return
  try {
    await remove.mutateAsync(row.uuid)
    success('Row deleted', 'The row was removed.')
    pendingDelete.value = null
  } catch (e) {
    // A restrict-referenced delete returns 409 — surfaced to the operator.
    notifyError(e, 'Couldn’t delete row')
  }
}
</script>

<template>
  <UDashboardPanel id="collection-data">
    <template #header>
      <UDashboardNavbar :title="`${collection?.label || name} · data`">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            :to="`/collections/${name}`"
            aria-label="Back to schema"
          />
        </template>
        <template #right>
          <UButton data-test="new-row" icon="i-lucide-plus" @click="openCreate">New row</UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable :data="pageData?.rows ?? []" :columns="columns" :loading="status === 'pending'">
        <template #actions-cell="{ row }">
          <div data-test="row" class="flex justify-end gap-1">
            <UButton
              size="xs"
              variant="ghost"
              icon="i-lucide-pencil"
              aria-label="Edit row"
              @click="openEdit(row.original)"
            />
            <UButton
              size="xs"
              color="error"
              variant="ghost"
              icon="i-lucide-trash-2"
              aria-label="Delete row"
              @click="pendingDelete = row.original"
            />
          </div>
        </template>

        <template #empty>
          <UEmpty icon="i-lucide-rows-3" title="No rows" description="Create the first row in this collection.">
            <template #actions>
              <UButton icon="i-lucide-plus" @click="openCreate">New row</UButton>
            </template>
          </UEmpty>
        </template>
      </UTable>

      <TablePagination
        v-model:page="page"
        v-model:perPage="perPage"
        :total="pageData?.total ?? 0"
        label="rows"
      />
    </template>
  </UDashboardPanel>

  <RowDrawer
    :open="drawerOpen"
    :collection="collection ?? null"
    :row="editingRow"
    :loading="saving"
    @update:open="drawerOpen = $event"
    @save="onSave"
  />

  <UModal
    :open="pendingDelete !== null"
    title="Delete row"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">Delete this row? This cannot be undone.</p>
    </template>
    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="pendingDelete = null"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: lemma.collections
</route>
