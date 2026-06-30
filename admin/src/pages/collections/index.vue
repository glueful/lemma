<script setup lang="ts">
import { ref } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import { useCollections, useCollectionMutations, type Collection } from '@/queries/collections'
import { useNotify } from '@/composables/useNotify'

const { success, error: notifyError } = useNotify()
const { data, status } = useCollections()
const { remove } = useCollectionMutations()

const columns: TableColumn<Collection>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'label', header: 'Label' },
  { accessorKey: 'fields', header: 'Fields' },
  { accessorKey: 'accessPolicy', header: 'Access (r·w·d)' },
  { id: 'actions', header: '' },
]

// Hold the pending row so the delete modal can name it (and supply the `confirm` token).
const pendingDelete = ref<Collection | null>(null)

async function confirmDelete() {
  const name = pendingDelete.value?.name
  if (name === undefined) return
  try {
    await remove.mutateAsync({ name, confirm: name })
    success('Collection deleted', `“${name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete collection')
  }
}
</script>

<template>
  <UDashboardPanel id="collections-schema">
    <template #header>
      <UDashboardNavbar title="Collections">
        <template #right>
          <UButton data-test="new-collection" icon="i-lucide-plus" to="/collections/new">
            New collection
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <p class="text-sm text-muted">Developer-defined data tables with an auto CRUD/query API.</p>

      <UTable :data="data ?? []" :columns="columns" :loading="status === 'pending'">
        <template #name-cell="{ row }">
          <ULink
            :to="`/collections/${row.original.name}`"
            data-test="collection-row"
            class="font-medium text-default"
          >
            {{ row.original.name }}
          </ULink>
        </template>

        <template #label-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.label }}</span>
        </template>

        <template #fields-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.fields.length }}</span>
        </template>

        <template #accessPolicy-cell="{ row }">
          <code class="text-xs text-muted">
            {{ row.original.accessPolicy.read }} · {{ row.original.accessPolicy.write }} ·
            {{ row.original.accessPolicy.delete }}
          </code>
        </template>

        <template #actions-cell="{ row }">
          <div class="flex justify-end gap-1">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-pencil"
              aria-label="Edit"
              :to="`/collections/${row.original.name}`"
            />
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-trash-2"
              aria-label="Delete"
              @click="pendingDelete = row.original"
            />
          </div>
        </template>

        <template #empty>
          <UEmpty
            icon="i-lucide-database"
            title="No collections"
            description="Create your first collection to start storing data."
          >
            <template #actions>
              <UButton icon="i-lucide-plus" to="/collections/new">New collection</UButton>
            </template>
          </UEmpty>
        </template>
      </UTable>
    </template>
  </UDashboardPanel>

  <UModal
    :open="pendingDelete !== null"
    title="Delete collection"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? This drops the
        underlying table and every row in it.
      </p>
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
