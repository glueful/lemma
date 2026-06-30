<script setup lang="ts">
import { reactive, ref, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import {
  useCollectionMutations,
  COLLECTION_FIELD_TYPES,
  type AccessLevel,
  type CollectionFieldType,
} from '@/queries/collections'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

const router = useRouter()
const { success, error: notifyError } = useNotify()
const { create } = useCollectionMutations()

const schema = z.object({
  name: z
    .string()
    .min(1, 'Name is required.')
    .regex(/^[a-z][a-z0-9_]*$/, 'Start with a lowercase letter; letters, numbers and underscores only.'),
  label: z.string().optional(),
})
type Schema = z.output<typeof schema>

const state = reactive({ name: '', label: '' })

interface FieldRow {
  name: string
  type: CollectionFieldType
}
const fields = ref<FieldRow[]>([{ name: '', type: 'collections.text' }])
const FIELD_TYPE_ITEMS = COLLECTION_FIELD_TYPES.map((t) => ({
  label: t.replace('collections.', ''),
  value: t,
}))

const ACCESS_LEVELS: AccessLevel[] = ['public', 'scoped']
const access = reactive<{ read: AccessLevel; write: AccessLevel; delete: AccessLevel }>({
  read: 'scoped',
  write: 'scoped',
  delete: 'scoped',
})

const createForm = useTemplateRef<Form<Schema>>('createForm')

function addField() {
  fields.value.push({ name: '', type: 'collections.text' })
}
function removeField(index: number) {
  fields.value.splice(index, 1)
}

async function onSubmit(event: FormSubmitEvent<Schema>) {
  const cleaned = fields.value
    .map((f) => ({ name: f.name.trim(), type: f.type, settings: {} }))
    .filter((f) => f.name !== '')

  try {
    await create.mutateAsync({
      name: event.data.name,
      label: event.data.label?.trim() || undefined,
      fields: cleaned,
      access: { read: access.read, write: access.write, delete: access.delete },
    })
    success('Collection created', `“${event.data.name}” is ready.`)
    await router.push('/collections')
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({ name, message }))
    if (fieldErrors.length > 0) createForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t create collection')
  }
}
</script>

<template>
  <UDashboardPanel id="collections-new">
    <template #header>
      <UDashboardNavbar title="New collection">
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
          <UButton variant="ghost" color="neutral" to="/collections">Cancel</UButton>
          <UButton type="submit" form="new-collection" :loading="create.isLoading.value">
            Create collection
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UForm
        id="new-collection"
        ref="createForm"
        :schema="schema"
        :state="state"
        class="space-y-6 max-w-2xl"
        @submit="onSubmit"
      >
        <UFormField label="Name" name="name" required help="The collection identifier (table-safe).">
          <UInput v-model="state.name" placeholder="posts" />
        </UFormField>

        <UFormField label="Label" name="label">
          <UInput v-model="state.label" placeholder="Posts" />
        </UFormField>

        <div class="space-y-2">
          <div class="flex items-center justify-between">
            <h3 class="text-sm font-medium text-default">Fields</h3>
            <UButton size="xs" variant="soft" icon="i-lucide-plus" @click="addField">Add field</UButton>
          </div>
          <div
            v-for="(field, i) in fields"
            :key="i"
            data-test="field-row"
            class="flex gap-2 items-center"
          >
            <UInput v-model="field.name" placeholder="field_name" class="flex-1" />
            <USelect v-model="field.type" :items="FIELD_TYPE_ITEMS" class="w-48" />
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-x"
              aria-label="Remove field"
              @click="removeField(i)"
            />
          </div>
        </div>

        <div class="space-y-2">
          <h3 class="text-sm font-medium text-default">Access policy</h3>
          <p class="text-xs text-muted">
            Per operation: <code>public</code> needs no auth; <code>scoped</code> needs the
            <code>{collection}.{action}</code> capability (api-key scope or session permission).
          </p>
          <div class="grid grid-cols-3 gap-3">
            <UFormField label="Read"><USelect v-model="access.read" :items="ACCESS_LEVELS" /></UFormField>
            <UFormField label="Write"><USelect v-model="access.write" :items="ACCESS_LEVELS" /></UFormField>
            <UFormField label="Delete"><USelect v-model="access.delete" :items="ACCESS_LEVELS" /></UFormField>
          </div>
        </div>
      </UForm>
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: lemma.collections
</route>
