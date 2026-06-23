<script setup lang="ts">
import { reactive, ref } from 'vue'
import * as z from 'zod'
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { useLocales, useLocaleMutations, type Locale } from '@/queries/locales'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useLocales()
const { create, update } = useLocaleMutations()

const columns: TableColumn<Locale>[] = [
  { accessorKey: 'code', header: 'Code' },
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'native_name', header: 'Native name' },
  { accessorKey: 'direction', header: 'Direction' },
  { accessorKey: 'enabled', header: 'Enabled' },
  { accessorKey: 'is_default', header: 'Default' },
  { id: 'actions', header: '' },
]

async function onToggleEnabled(locale: Locale, value: boolean) {
  try {
    await update.mutateAsync({ code: locale.code, input: { enabled: value } })
  } catch (e) {
    notifyError(e, 'Couldn’t update language')
  }
}

async function onSetDefault(locale: Locale) {
  try {
    await update.mutateAsync({ code: locale.code, input: { is_default: true } })
    success('Default language updated', `${locale.name} is now the default.`)
  } catch (e) {
    notifyError(e, 'Couldn’t set default')
  }
}

// ── Add language ──
const showAdd = ref(false)
const schema = z.object({
  code: z
    .string()
    .min(2, 'Enter a locale code (e.g. en, fr-CA).')
    .regex(/^[a-z]{2,3}(-[A-Za-z0-9]{2,})?$/, 'Use a code like “en” or “fr-CA”.'),
  name: z.string().min(1, 'Name is required.'),
  native_name: z.string().optional(),
})
type Schema = z.output<typeof schema>

const form = reactive({
  code: '',
  name: '',
  native_name: '',
  direction: 'ltr' as 'ltr' | 'rtl',
  enabled: true,
  is_default: false,
})

function resetForm() {
  Object.assign(form, {
    code: '',
    name: '',
    native_name: '',
    direction: 'ltr',
    enabled: true,
    is_default: false,
  })
}

async function onCreate(event: FormSubmitEvent<Schema>) {
  try {
    await create.mutateAsync({
      code: event.data.code,
      name: event.data.name,
      native_name: event.data.native_name || undefined,
      direction: form.direction,
      enabled: form.enabled,
      is_default: form.is_default,
    })
    success('Language added', `${event.data.name} (${event.data.code})`)
    showAdd.value = false
    resetForm()
  } catch (e) {
    notifyError(e, 'Couldn’t add language')
  }
}
</script>

<template>
  <UDashboardPanel id="settings-languages">
    <template #header>
      <UDashboardNavbar title="Languages">
        <template #right>
          <UButton icon="i-lucide-plus" @click="showAdd = true">Add language</UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable :data="data ?? []" :columns="columns" :loading="status === 'pending'">
        <template #code-cell="{ row }">
          <code class="text-xs text-muted">{{ row.original.code }}</code>
        </template>

        <template #native_name-cell="{ row }">
          <span class="text-default">{{ row.original.native_name || '—' }}</span>
        </template>

        <template #direction-cell="{ row }">
          <UBadge color="neutral" variant="subtle" size="sm">{{ row.original.direction }}</UBadge>
        </template>

        <template #enabled-cell="{ row }">
          <USwitch
            :model-value="row.original.enabled"
            :loading="update.isLoading.value"
            @update:model-value="onToggleEnabled(row.original, $event)"
          />
        </template>

        <template #is_default-cell="{ row }">
          <UBadge v-if="row.original.is_default" color="primary" variant="subtle" size="sm">
            Default
          </UBadge>
          <span v-else class="text-muted">—</span>
        </template>

        <template #actions-cell="{ row }">
          <div class="flex justify-end">
            <UButton
              v-if="!row.original.is_default"
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-star"
              :loading="update.isLoading.value"
              @click="onSetDefault(row.original)"
            >
              Set default
            </UButton>
          </div>
        </template>

        <template #empty>
          <UEmpty
            icon="i-lucide-languages"
            title="No languages"
            description="Add a language to start localizing content."
          >
            <template #actions>
              <UButton icon="i-lucide-plus" @click="showAdd = true">Add language</UButton>
            </template>
          </UEmpty>
        </template>
      </UTable>
    </template>
  </UDashboardPanel>

  <UModal v-model:open="showAdd" title="Add language">
    <template #body>
      <UForm id="add-locale" :schema="schema" :state="form" class="space-y-4" @submit="onCreate">
        <UFormField label="Code" name="code" hint="e.g. en, fr-CA">
          <UInput v-model="form.code" placeholder="en" class="w-full" />
        </UFormField>
        <UFormField label="Name" name="name">
          <UInput v-model="form.name" placeholder="English" class="w-full" />
        </UFormField>
        <UFormField label="Native name" name="native_name">
          <UInput v-model="form.native_name" placeholder="English" class="w-full" />
        </UFormField>
        <div class="flex flex-wrap items-center gap-4">
          <UFormField label="Direction">
            <USelect v-model="form.direction" :items="['ltr', 'rtl']" class="w-28" />
          </UFormField>
          <USwitch v-model="form.enabled" label="Enabled" class="self-end pb-2" />
          <USwitch v-model="form.is_default" label="Set as default" class="self-end pb-2" />
        </div>
      </UForm>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton color="neutral" variant="ghost" @click="showAdd = false">Cancel</UButton>
        <UButton type="submit" form="add-locale" :loading="create.isLoading.value">
          Add language
        </UButton>
      </div>
    </template>
  </UModal>
</template>
