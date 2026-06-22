<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useContentTypes } from '@/queries/contentTypes'
import { useDraft, useSaveDraft } from '@/queries/drafts'
import { runtimeConfig } from '@/runtime/config'
import type { FieldDef } from '@/fields/types'
import FieldEditor from '@/components/FieldEditor.vue'
import { ApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import PublishPanel from './components/PublishPanel.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const type = computed(() => String(route.params.type))
const uuid = computed(() => String(route.params.uuid))
// Phase 1 is en-only in the UI; locale comes from runtime config.
const locale = runtimeConfig.defaultLocale

const { success, warning, error: notifyError } = useNotify()

// The content-type schema drives the field editor.
const { data: contentTypes } = useContentTypes()
const schema = computed<FieldDef[]>(() =>
  (contentTypes.value?.find((c) => c.slug === type.value)?.schema ?? []).map((f) => ({
    name: String(f.name ?? ''),
    type: (f.type ?? 'string') as FieldDef['type'],
    required: f.required ?? undefined,
    enum: f.enum ?? undefined,
  })),
)

const { data: draft, status: draftStatus } = useDraft(uuid, () => locale)

// Local editable copy, seeded from the loaded draft. lock_version is echoed back on save for
// optimistic concurrency.
const fields = ref<Record<string, unknown>>({})
const lockVersion = ref(0)
watch(
  draft,
  (d) => {
    if (d) {
      fields.value = { ...d.fields }
      lockVersion.value = d.lock_version
    }
  },
  { immediate: true },
)

const save = useSaveDraft(uuid.value, locale, type.value)

async function onSave() {
  try {
    await save.mutateAsync({ fields: fields.value, lock_version: lockVersion.value })
    success('Draft saved')
  } catch (e: unknown) {
    if (e instanceof ApiError && e.status === 409) {
      warning(
        'This draft changed elsewhere',
        'Reload to get the latest version before saving again.',
      )
    } else {
      notifyError(e, 'Couldn’t save draft')
    }
  }
}
</script>

<template>
  <div class="space-y-6 p-6">
    <header class="flex items-center justify-between gap-4">
      <div>
        <h1 class="text-xl font-semibold capitalize text-highlighted">{{ type }}</h1>
        <p class="text-xs text-muted">{{ uuid }}</p>
      </div>
      <div class="flex items-center gap-2">
        <UButton
          variant="ghost"
          color="neutral"
          icon="i-lucide-history"
          :to="`/content/${type}/${uuid}/versions`"
        >
          Versions
        </UButton>
        <UButton :loading="save.isLoading.value" @click="onSave">Save draft</UButton>
      </div>
    </header>

    <div v-if="draftStatus === 'pending'" class="space-y-3">
      <USkeleton v-for="n in 4" :key="n" class="h-10" />
    </div>

    <FieldEditor v-else v-model="fields" :schema="schema" />

    <PublishPanel :key="uuid" :uuid="uuid" :locale="locale" :type="type" />
  </div>
</template>
