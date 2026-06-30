<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  useCollection,
  useCollectionMutations,
  type AccessLevel,
  type CollectionField,
} from '@/queries/collections'
import { useNotify } from '@/composables/useNotify'
import FieldEditor from '../components/FieldEditor.vue'
import DropConfirmModal from '../components/DropConfirmModal.vue'
import ScopesPanel from './components/ScopesPanel.vue'

const route = useRoute()
const router = useRouter()
const { success, error: notifyError } = useNotify()

const name = computed(() => String(route.params.name))
const { data, status } = useCollection(name)
const { addField, dropField, addIndex, dropIndex, updateAccess, remove } = useCollectionMutations()

function isIndexed(field: CollectionField): boolean {
  return field.settings?.index === true || field.settings?.unique === true
}

async function onAddField(field: { name: string; type: string; settings: Record<string, unknown> }) {
  try {
    await addField.mutateAsync({ name: name.value, field })
    success('Field added', `“${field.name}” was added.`)
  } catch (e) {
    notifyError(e, 'Couldn’t add field')
  }
}

async function onToggleIndex(field: CollectionField) {
  try {
    if (isIndexed(field)) {
      await dropIndex.mutateAsync({ name: name.value, field: field.name })
      success('Index removed', `Index on “${field.name}” removed.`)
    } else {
      await addIndex.mutateAsync({ name: name.value, field: field.name, unique: false })
      success('Index added', `Index on “${field.name}” added.`)
    }
  } catch (e) {
    notifyError(e, 'Couldn’t update index')
  }
}

const pendingFieldDrop = ref<CollectionField | null>(null)
async function onConfirmFieldDrop(token: string | undefined) {
  const field = pendingFieldDrop.value
  if (!field) return
  try {
    await dropField.mutateAsync({ name: name.value, field: field.name, confirm: token })
    success('Field dropped', `“${field.name}” was dropped.`)
    pendingFieldDrop.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t drop field')
  }
}

const dropCollectionOpen = ref(false)
async function onConfirmCollectionDrop(token: string | undefined) {
  try {
    await remove.mutateAsync({ name: name.value, confirm: token })
    success('Collection dropped', `“${name.value}” was removed.`)
    await router.push('/collections')
  } catch (e) {
    notifyError(e, 'Couldn’t drop collection')
  }
}

const access = ref<{ read: AccessLevel; write: AccessLevel; delete: AccessLevel }>({
  read: 'scoped',
  write: 'scoped',
  delete: 'scoped',
})
watch(
  data,
  (collection) => {
    if (collection) access.value = { ...collection.accessPolicy }
  },
  { immediate: true },
)
const ACCESS_LEVELS: AccessLevel[] = ['public', 'scoped']

async function onSaveAccess() {
  try {
    await updateAccess.mutateAsync({ name: name.value, access: access.value })
    success('Access policy saved', 'Per-operation access was updated.')
  } catch (e) {
    notifyError(e, 'Couldn’t save access policy')
  }
}
</script>

<template>
  <UDashboardPanel id="collection-edit">
    <template #header>
      <UDashboardNavbar :title="data?.label || name">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/collections"
            aria-label="Back to collections"
          />
        </template>
        <template #right>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-table"
            :to="`/collections/${name}/data`"
          >
            Browse data
          </UButton>
          <UButton
            color="error"
            variant="soft"
            icon="i-lucide-trash-2"
            data-test="drop-collection"
            @click="dropCollectionOpen = true"
          >
            Drop collection
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div v-if="status === 'pending'" class="text-sm text-muted">Loading…</div>

      <template v-else-if="data">
        <section class="space-y-3">
          <h3 class="text-sm font-medium text-default">Fields</h3>
          <div class="divide-y divide-default rounded-md border border-default">
            <div
              v-for="field in data.fields"
              :key="field.name"
              data-test="field-row"
              class="flex items-center gap-3 px-3 py-2"
            >
              <span class="font-medium text-default flex-1">{{ field.name }}</span>
              <code class="text-xs text-muted">{{ field.type.replace('collections.', '') }}</code>
              <UBadge v-if="isIndexed(field)" color="info" variant="subtle" size="xs">indexed</UBadge>
              <UButton
                size="xs"
                color="neutral"
                variant="ghost"
                :label="isIndexed(field) ? 'Remove index' : 'Add index'"
                @click="onToggleIndex(field)"
              />
              <UButton
                size="xs"
                color="error"
                variant="ghost"
                icon="i-lucide-trash-2"
                aria-label="Drop field"
                @click="pendingFieldDrop = field"
              />
            </div>
            <p v-if="data.fields.length === 0" class="px-3 py-2 text-sm text-muted">No fields yet.</p>
          </div>

          <FieldEditor @add="onAddField" />
        </section>

        <section class="space-y-2 mt-6">
          <h3 class="text-sm font-medium text-default">Access policy</h3>
          <div class="grid grid-cols-3 gap-3">
            <UFormField label="Read"><USelect v-model="access.read" :items="ACCESS_LEVELS" /></UFormField>
            <UFormField label="Write"><USelect v-model="access.write" :items="ACCESS_LEVELS" /></UFormField>
            <UFormField label="Delete"><USelect v-model="access.delete" :items="ACCESS_LEVELS" /></UFormField>
          </div>
          <UButton size="sm" :loading="updateAccess.isLoading.value" @click="onSaveAccess">
            Save access policy
          </UButton>
        </section>

        <section class="mt-6">
          <ScopesPanel :collection-name="name" />
        </section>
      </template>
    </template>
  </UDashboardPanel>

  <DropConfirmModal
    :open="pendingFieldDrop !== null"
    title="Drop field"
    :confirm-name="pendingFieldDrop?.name ?? ''"
    :loading="dropField.isLoading.value"
    :message="`Drop the “${pendingFieldDrop?.name}” column? Its data is lost.`"
    @confirm="onConfirmFieldDrop"
    @cancel="pendingFieldDrop = null"
  />

  <DropConfirmModal
    :open="dropCollectionOpen"
    title="Drop collection"
    :confirm-name="name"
    :loading="remove.isLoading.value"
    :message="`Drop “${name}”? This drops the table and every row in it.`"
    @confirm="onConfirmCollectionDrop"
    @cancel="dropCollectionOpen = false"
  />
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: lemma.collections
</route>
