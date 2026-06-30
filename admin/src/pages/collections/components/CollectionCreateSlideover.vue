<script setup lang="ts">
import { reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent, TabsItem } from '@nuxt/ui'
import {
  useCollectionMutations,
  COLLECTION_FIELD_TYPES,
  COLLECTION_FIELD_TYPE_META,
  type AccessLevel,
  type CollectionFieldType,
} from '@/queries/collections'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import FieldCard from './FieldCard.vue'
import { VueDraggable } from 'vue-draggable-plus'

const props = defineProps<{ open: boolean }>()
const emit = defineEmits<{ 'update:open': [value: boolean]; created: [name: string] }>()

const { success, error: notifyError } = useNotify()
const { create } = useCollectionMutations()

const schema = z.object({
  name: z
    .string()
    .min(1, 'Name is required.')
    .regex(
      /^[a-z][a-z0-9_]*$/,
      'Start with a lowercase letter; letters, numbers and underscores only.',
    ),
})
type Schema = z.output<typeof schema>
const state = reactive({ name: '' })

let fieldSeq = 0
interface FieldRow {
  id: number
  name: string
  type: CollectionFieldType
  settings: Record<string, unknown>
  open: boolean
  system?: boolean
}
function blankField(type: CollectionFieldType): FieldRow {
  return { id: fieldSeq++, name: '', type, settings: {}, open: true }
}
// Columns every collection gets automatically — shown read-only, in the list, so they sit in place.
// The backend always creates them first regardless of where they're dragged.
function systemFields(): FieldRow[] {
  return [
    {
      id: fieldSeq++,
      name: 'id',
      type: 'collections.integer',
      settings: { nullable: false, unique: true },
      open: false,
      system: true,
    },
    {
      id: fieldSeq++,
      name: 'uuid',
      type: 'collections.string',
      settings: { nullable: false, unique: true, length: 64 },
      open: false,
      system: true,
    },
    {
      id: fieldSeq++,
      name: 'created_at',
      type: 'collections.datetime',
      settings: { nullable: true },
      open: false,
      system: true,
    },
    {
      id: fieldSeq++,
      name: 'updated_at',
      type: 'collections.datetime',
      settings: { nullable: true },
      open: false,
      system: true,
    },
  ]
}
const fields = ref<FieldRow[]>(systemFields())
// Add-field menu: one entry per type, with its icon; selecting appends an open card.
const addItems = COLLECTION_FIELD_TYPES.map((t) => ({
  label: COLLECTION_FIELD_TYPE_META[t].label,
  icon: COLLECTION_FIELD_TYPE_META[t].icon,
  onSelect: () => addField(t),
}))

const ACCESS_LEVELS: AccessLevel[] = ['public', 'scoped']
const access = reactive<{ read: AccessLevel; write: AccessLevel; delete: AccessLevel }>({
  read: 'scoped',
  write: 'scoped',
  delete: 'scoped',
})

const tabs: TabsItem[] = [
  { label: 'Fields', slot: 'fields', icon: 'i-lucide-table-properties' },
  { label: 'Access policy', slot: 'access', icon: 'i-lucide-shield' },
]

const createForm = useTemplateRef<Form<Schema>>('createForm')

// Reset to a clean form each time the slideover opens.
watch(
  () => props.open,
  (open) => {
    if (!open) return
    state.name = ''
    fields.value = systemFields()
    access.read = 'scoped'
    access.write = 'scoped'
    access.delete = 'scoped'
  },
)

function addField(type: CollectionFieldType) {
  fields.value.push(blankField(type))
}
function removeField(id: number) {
  fields.value = fields.value.filter((f) => f.id !== id)
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  const cleaned = fields.value
    .filter((f) => !f.system)
    .map((f) => ({ name: f.name.trim(), type: f.type, settings: f.settings }))
    .filter((f) => f.name !== '')

  // Full display order (system + named custom fields) as the cards are arranged.
  const fieldOrder = fields.value
    .filter((f) => f.system || f.name.trim() !== '')
    .map((f) => f.name.trim())

  try {
    await create.mutateAsync({
      name: event.data.name,
      fields: cleaned,
      access: { read: access.read, write: access.write, delete: access.delete },
      field_order: fieldOrder,
    })
    success('Collection created', `“${event.data.name}” is ready.`)
    emit('created', event.data.name)
    emit('update:open', false)
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) createForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t create collection')
  }
}
</script>

<template>
  <USlideover
    :open="open"
    title="Create collection"
    :ui="{ content: 'sm:max-w-2xl' }"
    @update:open="(v: boolean) => emit('update:open', v)"
  >
    <template #body>
      <UForm
        id="collection-create-form"
        ref="createForm"
        :schema="schema"
        :state="state"
        class="space-y-6"
        @submit="onSubmit"
      >
        <UFormField
          label="Name"
          name="name"
          required
          help="Lowercase, table-safe identifier — drives the table, API path and permissions."
        >
          <UInput v-model="state.name" placeholder="e.g. posts" class="w-full" />
        </UFormField>

        <UTabs :items="tabs" variant="link" class="w-full" :ui="{ content: 'pt-4' }">
          <template #fields>
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <p class="text-xs font-medium uppercase tracking-wide text-muted">
                  Fields
                  <span class="ml-1 normal-case text-dimmed">· drag to reorder</span>
                </p>
                <UDropdownMenu :items="addItems" :content="{ align: 'end' }">
                  <UButton
                    variant="soft"
                    color="neutral"
                    icon="i-lucide-plus"
                    data-test="add-field"
                  >
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
                  @remove="removeField(field.id)"
                />
              </VueDraggable>
            </div>
          </template>

          <template #access>
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
          </template>
        </UTabs>
      </UForm>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between">
        <UButton
          color="neutral"
          variant="ghost"
          label="Close"
          @click="emit('update:open', false)"
        />
        <UButton
          type="submit"
          form="collection-create-form"
          :loading="create.isLoading.value"
          label="Create"
        />
      </div>
    </template>
  </USlideover>
</template>
