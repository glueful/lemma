<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoutes, useSaveRoute } from '@/queries/routes'
import { usePublish } from '@/queries/publish'
import { usePreview, buildPreviewUrl } from '@/queries/preview'
import { useSchedules, useScheduleMutations } from '@/queries/schedules'

const props = defineProps<{ uuid: string; locale: string; type: string }>()
const toast = useToast()

// ── Route / slug ──────────────────────────────────────────────────────────
const { data: routes } = useRoutes(() => props.uuid)
const slug = ref('')
watch(
  routes,
  (r) => {
    const match = r?.find((x) => x.locale === props.locale)
    if (match) slug.value = match.slug
  },
  { immediate: true },
)
const saveRoute = useSaveRoute(props.uuid, props.locale)
async function onSaveRoute() {
  try {
    await saveRoute.mutateAsync(slug.value)
    toast.add({ title: 'Route saved', color: 'success' })
  } catch {
    toast.add({ title: 'Could not save route (the slug may conflict)', color: 'error' })
  }
}

// ── Publish / unpublish ─────────────────────────────────────────────────────
const publish = usePublish(props.uuid, props.locale, props.type)
async function onPublish(action: 'publish' | 'unpublish') {
  try {
    await publish.mutateAsync(action)
    toast.add({ title: action === 'publish' ? 'Published' : 'Unpublished', color: 'success' })
  } catch {
    toast.add({ title: 'Action failed', color: 'error' })
  }
}

// ── Preview ─────────────────────────────────────────────────────────────────
const preview = usePreview(props.uuid, props.locale)
async function onPreview() {
  try {
    const url = buildPreviewUrl(await preview.mutateAsync())
    if (url) window.open(url, '_blank', 'noopener')
    else toast.add({ title: 'No preview URL is configured', color: 'warning' })
  } catch {
    toast.add({ title: 'Preview failed', color: 'error' })
  }
}

// ── Schedule ─────────────────────────────────────────────────────────────────
const { data: schedules } = useSchedules(() => props.uuid)
const runAt = ref('')
const { create: createSchedule, cancel: cancelSchedule } = useScheduleMutations(
  props.uuid,
  props.locale,
)
async function onSchedule() {
  if (!runAt.value) return
  try {
    await createSchedule.mutateAsync({
      action: 'publish',
      run_at: new Date(runAt.value).toISOString(),
    })
    runAt.value = ''
    toast.add({ title: 'Scheduled', color: 'success' })
  } catch {
    toast.add({ title: 'Could not schedule', color: 'error' })
  }
}
const localeSchedules = computed(() =>
  (schedules.value ?? []).filter((s) => !s.locale || s.locale === props.locale),
)
</script>

<template>
  <UCard>
    <template #header>
      <h2 class="font-semibold text-default">Publishing</h2>
    </template>

    <div class="space-y-5">
      <div class="flex items-end gap-2">
        <UFormField label="Slug" class="flex-1">
          <UInput v-model="slug" placeholder="my-page" class="w-full" />
        </UFormField>
        <UButton variant="subtle" :loading="saveRoute.isLoading.value" @click="onSaveRoute">
          Save route
        </UButton>
      </div>

      <div class="flex items-center gap-2">
        <UButton :loading="publish.isLoading.value" @click="onPublish('publish')">Publish</UButton>
        <UButton
          color="neutral"
          variant="subtle"
          :loading="publish.isLoading.value"
          @click="onPublish('unpublish')"
        >
          Unpublish
        </UButton>
        <UButton
          color="neutral"
          variant="ghost"
          icon="i-lucide-eye"
          :loading="preview.isLoading.value"
          @click="onPreview"
        >
          Preview
        </UButton>
      </div>

      <div class="space-y-2">
        <div class="flex items-end gap-2">
          <UFormField label="Schedule publish" class="flex-1">
            <UInput v-model="runAt" type="datetime-local" class="w-full" />
          </UFormField>
          <UButton
            variant="subtle"
            :disabled="!runAt"
            :loading="createSchedule.isLoading.value"
            @click="onSchedule"
          >
            Schedule
          </UButton>
        </div>
        <ul v-if="localeSchedules.length" class="space-y-1">
          <li
            v-for="s in localeSchedules"
            :key="s.uuid"
            class="flex items-center justify-between text-sm"
          >
            <span class="text-muted">
              {{ s.action }} · {{ s.run_at }}
              <UBadge size="sm" variant="subtle">{{ s.status ?? 'pending' }}</UBadge>
            </span>
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-x"
              @click="cancelSchedule.mutate(s.uuid)"
            />
          </li>
        </ul>
      </div>
    </div>
  </UCard>
</template>
