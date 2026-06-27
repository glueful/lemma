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
    // Carry the widget hint through so `text` + `rich` renders the RichText (UEditor) editor,
    // matching the content-type preview — without this it falls back to a plain textarea.
    format: (f.format ?? undefined) as FieldDef['format'],
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
  <UDashboardPanel id="entry-editor">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            :to="`/content/${type}`"
            :aria-label="`Back to ${type}`"
          />
        </template>
        <template #title
          ><span class="capitalize">{{ type }}</span></template
        >
        <template #right>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-history"
            :to="`/content/${type}/${uuid}/versions`"
          >
            Versions
          </UButton>
          <UButton :loading="save.isLoading.value" @click="onSave">Save draft</UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <!-- On large screens the body fills the panel height and each pane scrolls on its own, so the
           page chrome stays put. On small screens it stacks and scrolls normally. -->
      <div class="flex w-full flex-col gap-6 lg:h-full lg:min-h-0 lg:flex-row">
        <!-- Entry content — the primary, wider pane -->
        <div class="min-w-0 lg:min-h-0 lg:flex-1 lg:overflow-y-auto lg:pe-1">
          <UCard :ui="{ root: 'ring-0' }">
            <div v-if="draftStatus === 'pending'" class="space-y-3">
              <USkeleton v-for="n in 4" :key="n" class="h-10" />
            </div>
            <FieldEditor v-else v-model="fields" :schema="schema" />
          </UCard>
        </div>

        <!-- Publishing — the narrower sidebar, its own scroll section. p-1 gives the card's ring room
             on every side: the scroll container would otherwise clip the outline (overflow-y-auto
             makes overflow-x compute to auto, and the top/bottom edges clip at the scroll extremes). -->
        <div class="lg:min-h-0 lg:w-96 lg:shrink-0 lg:overflow-y-auto lg:p-1">
          <PublishPanel :key="uuid" :uuid="uuid" :locale="locale" :type="type" />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
