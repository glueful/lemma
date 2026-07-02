<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { TabsItem } from '@nuxt/ui'
import { VueDraggable } from 'vue-draggable-plus'
import {
  useCollection,
  useCollectionMutations,
  COLLECTION_FIELD_TYPES,
  COLLECTION_FIELD_TYPE_META,
  type AccessLevel,
  type CollectionFieldType,
  type Collection,
} from '@/queries/collections'
import { useNotify } from '@/composables/useNotify'
import FieldCard from './FieldCard.vue'
import DropConfirmModal from './DropConfirmModal.vue'
import ScopesPanel from '../[name]/components/ScopesPanel.vue'

const props = defineProps<{ open: boolean; name: string }>()
const emit = defineEmits<{
  'update:open': [value: boolean]
  dropped: [name: string]
  duplicate: [collection: Collection]
}>()

const { success, error: notifyError } = useNotify()
const {
  addField,
  dropField,
  addIndex,
  dropIndex,
  updateAccess,
  updateFieldOrder,
  truncate,
  remove,
} = useCollectionMutations()

const { data: collection } = useCollection(computed(() => props.name))

let seq = 0
interface FieldRow {
  id: number
  name: string
  type: CollectionFieldType
  settings: Record<string, unknown>
  open: boolean
  system?: boolean
  existing?: boolean
  // Index/unique state when the slideover opened, to diff in-place index changes on save.
  origIndex?: boolean
  origUnique?: boolean
}

// The four columns every collection gets automatically — shown read-only, draggable, in the list.
const SYSTEM_FIELDS = [
  { name: 'id', type: 'collections.integer', settings: { nullable: false, unique: true } },
  {
    name: 'uuid',
    type: 'collections.string',
    settings: { nullable: false, unique: true, length: 64 },
  },
  { name: 'created_at', type: 'collections.datetime', settings: { nullable: true } },
  { name: 'updated_at', type: 'collections.datetime', settings: { nullable: true } },
] as const

const fields = ref<FieldRow[]>([])
const droppedNames = ref<string[]>([])
const access = reactive<{ read: AccessLevel; write: AccessLevel; delete: AccessLevel }>({
  read: 'scoped',
  write: 'scoped',
  delete: 'scoped',
})

function buildFields(col: Collection): FieldRow[] {
  const rows: FieldRow[] = [
    ...SYSTEM_FIELDS.map((s) => ({
      id: seq++,
      name: s.name,
      type: s.type as CollectionFieldType,
      settings: { ...s.settings },
      open: false,
      system: true,
    })),
    ...col.fields.map((f) => ({
      id: seq++,
      name: f.name,
      type: f.type as CollectionFieldType,
      settings: { ...f.settings },
      open: false,
      existing: true,
      origIndex: f.settings.index === true,
      origUnique: f.settings.unique === true,
    })),
  ]
  // Sort by the stored display order; columns absent from it fall to the end.
  const idx = (n: string) => {
    const i = col.fieldOrder.indexOf(n)
    return i === -1 ? Number.MAX_SAFE_INTEGER : i
  }
  return rows.sort((a, b) => idx(a.name) - idx(b.name))
}

// Re-seed each time the slideover opens (or the loaded collection arrives).
watch(
  [() => props.open, collection],
  ([open, col]) => {
    if (!open || !col) return
    fields.value = buildFields(col)
    droppedNames.value = []
    access.read = col.accessPolicy.read
    access.write = col.accessPolicy.write
    access.delete = col.accessPolicy.delete
  },
  { immediate: true },
)

const addItems = COLLECTION_FIELD_TYPES.map((t) => ({
  label: COLLECTION_FIELD_TYPE_META[t].label,
  icon: COLLECTION_FIELD_TYPE_META[t].icon,
  onSelect: () => addNewField(t),
}))
function addNewField(type: CollectionFieldType) {
  // Land new fields above the timestamps (…custom…, created_at, updated_at).
  const at = fields.value.findIndex((f) => f.name === 'created_at')
  fields.value.splice(at === -1 ? fields.value.length : at, 0, {
    id: seq++,
    name: '',
    type,
    settings: {},
    open: true,
  })
}

// Removing a new (unsaved) card is local; removing an existing field needs a typed confirmation.
const pendingDrop = ref<FieldRow | null>(null)
function onRemove(row: FieldRow) {
  if (row.existing) {
    pendingDrop.value = row
    return
  }
  fields.value = fields.value.filter((f) => f.id !== row.id)
}
function confirmDrop() {
  const row = pendingDrop.value
  if (!row) return
  droppedNames.value.push(row.name)
  fields.value = fields.value.filter((f) => f.id !== row.id)
  pendingDrop.value = null
}

const tabs: TabsItem[] = [
  { label: 'Fields', slot: 'fields', icon: 'i-lucide-table-properties' },
  { label: 'Access policy', slot: 'access', icon: 'i-lucide-shield' },
]
const ACCESS_LEVELS: AccessLevel[] = ['public', 'scoped']

const saving = ref(false)
async function onSave() {
  saving.value = true
  try {
    // 1) Drop removed fields (confirmed above). 2) Add new ones. 3) Persist order. 4) Access.
    for (const fieldName of droppedNames.value) {
      await dropField.mutateAsync({ name: props.name, field: fieldName, confirm: fieldName })
    }
    for (const f of fields.value) {
      if (f.system || f.existing) continue
      const fn = f.name.trim()
      if (fn === '') continue
      await addField.mutateAsync({
        name: props.name,
        field: { name: fn, type: f.type, settings: f.settings },
      })
    }
    // Index/unique changes on existing fields (the only safe in-place alter).
    for (const f of fields.value) {
      if (!f.existing) continue
      const wasIndexed = f.origIndex === true || f.origUnique === true
      const isIndexed = f.settings.index === true || f.settings.unique === true
      const isUnique = f.settings.unique === true
      if (isIndexed === wasIndexed && isUnique === (f.origUnique === true)) continue
      if (wasIndexed) await dropIndex.mutateAsync({ name: props.name, field: f.name })
      if (isIndexed) {
        await addIndex.mutateAsync({ name: props.name, field: f.name, unique: isUnique })
      }
    }
    const order = fields.value
      .filter((f) => f.system || f.name.trim() !== '')
      .map((f) => f.name.trim())
    await updateFieldOrder.mutateAsync({ name: props.name, order })
    await updateAccess.mutateAsync({
      name: props.name,
      access: { read: access.read, write: access.write, delete: access.delete },
    })

    success('Collection updated', 'Schema changes were saved.')
    emit('update:open', false)
  } catch (e) {
    notifyError(e, 'Couldn’t save changes')
  } finally {
    saving.value = false
  }
}

const dropCollectionOpen = ref(false)
async function confirmDropCollection(token: string | undefined) {
  try {
    await remove.mutateAsync({ name: props.name, confirm: token })
    success('Collection dropped', `“${props.name}” was removed.`)
    dropCollectionOpen.value = false
    emit('update:open', false)
    emit('dropped', props.name)
  } catch (e) {
    notifyError(e, 'Couldn’t drop collection')
  }
}

const truncateOpen = ref(false)
async function confirmTruncate(token: string | undefined) {
  try {
    await truncate.mutateAsync({ name: props.name, confirm: token })
    success('Collection truncated', `All rows in “${props.name}” were deleted.`)
    truncateOpen.value = false
  } catch (e) {
    notifyError(e, 'Couldn’t truncate the collection')
  }
}

function copyJson() {
  const def = collection.value
  if (!def) return
  const json = JSON.stringify(
    {
      name: def.name,
      label: def.label,
      fields: def.fields,
      access: def.accessPolicy,
      field_order: def.fieldOrder,
    },
    null,
    2,
  )
  navigator.clipboard?.writeText(json)
  success('Copied', 'Collection definition copied as JSON.')
}

function duplicate() {
  if (!collection.value) return
  emit('duplicate', collection.value)
  emit('update:open', false)
}

const menuItems = [
  [
    { label: 'Copy JSON', icon: 'i-lucide-braces', onSelect: copyJson },
    { label: 'Duplicate', icon: 'i-lucide-copy', onSelect: duplicate },
  ],
  [
    {
      label: 'Truncate',
      icon: 'i-lucide-eraser',
      color: 'warning' as const,
      onSelect: () => {
        truncateOpen.value = true
      },
    },
    {
      label: 'Delete',
      icon: 'i-lucide-trash-2',
      color: 'error' as const,
      onSelect: () => {
        dropCollectionOpen.value = true
      },
    },
  ],
]
</script>

<template>
  <USlideover
    :open="open"
    :title="`Edit ${collection?.label || name}`"
    :ui="{ content: 'sm:max-w-2xl' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <div class="mb-3 flex justify-end">
        <UDropdownMenu :items="menuItems" :content="{ align: 'end' }">
          <UButton
            icon="i-lucide-ellipsis"
            color="neutral"
            variant="ghost"
            aria-label="Collection actions"
          />
        </UDropdownMenu>
      </div>
      <UTabs :items="tabs" variant="link" class="w-full" :ui="{ content: 'pt-4' }">
        <template #fields>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <p class="text-xs font-medium uppercase tracking-wide text-muted">
                Fields
                <span class="ml-1 normal-case text-dimmed">· drag to reorder</span>
              </p>
              <UDropdownMenu :items="addItems" :content="{ align: 'end' }">
                <UButton variant="soft" color="neutral" icon="i-lucide-plus" data-test="add-field">
                  New field
                </UButton>
              </UDropdownMenu>
            </div>

            <VueDraggable
              v-model="fields"
              handle=".field-drag-handle"
              :animation="150"
              class="space-y-2"
            >
              <FieldCard
                v-for="(field, i) in fields"
                :key="field.id"
                v-model="fields[i]"
                draggable
                @remove="onRemove(field)"
              />
            </VueDraggable>
          </div>
        </template>

        <template #access>
          <div class="space-y-6">
            <div class="space-y-3">
              <p class="text-xs text-muted">
                Per operation: <code>public</code> needs no auth; <code>scoped</code> needs the
                <code>{collection}.{action}</code> capability (api-key scope or session permission).
              </p>
              <div class="grid grid-cols-3 gap-3">
                <UFormField label="Read">
                  <USelect v-model="access.read" :items="ACCESS_LEVELS" class="w-full" />
                </UFormField>
                <UFormField label="Write">
                  <USelect v-model="access.write" :items="ACCESS_LEVELS" class="w-full" />
                </UFormField>
                <UFormField label="Delete">
                  <USelect v-model="access.delete" :items="ACCESS_LEVELS" class="w-full" />
                </UFormField>
              </div>
            </div>

            <ScopesPanel :collection-name="name" />
          </div>
        </template>
      </UTabs>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Close"
          :disabled="saving"
          @click="emit('update:open', false)"
        />
        <UButton label="Save changes" :loading="saving" @click="onSave" />
      </div>
    </template>
  </USlideover>

  <DropConfirmModal
    :open="pendingDrop !== null"
    title="Drop field"
    :confirm-name="pendingDrop?.name ?? ''"
    :message="`Drop the “${pendingDrop?.name}” column? Its data is lost when you save.`"
    @confirm="confirmDrop"
    @cancel="pendingDrop = null"
  />

  <DropConfirmModal
    :open="dropCollectionOpen"
    title="Drop collection"
    :confirm-name="name"
    :loading="remove.isLoading.value"
    :message="`Drop “${name}”? This drops the table and every row in it.`"
    @confirm="confirmDropCollection"
    @cancel="dropCollectionOpen = false"
  />

  <DropConfirmModal
    :open="truncateOpen"
    title="Truncate collection"
    :confirm-name="name"
    :loading="truncate.isLoading.value"
    :message="`Delete every row in “${name}”? The schema is kept; the row data cannot be recovered.`"
    @confirm="confirmTruncate"
    @cancel="truncateOpen = false"
  />
</template>
