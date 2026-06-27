<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import {
  useGeneralSettings,
  useGeneralSettingsMutations,
  type GeneralSettings,
} from '@/queries/generalSettings'
import { useLocales } from '@/queries/locales'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useGeneralSettings()
const { save } = useGeneralSettingsMutations()
const { data: locales } = useLocales()

const form = reactive<GeneralSettings>({
  site_name: '',
  site_preview_url: '',
  default_locale: 'en',
  default_per_page: 20,
  max_per_page: 100,
  cache_ttl: 60,
  scheduler_enabled: true,
  webhooks_enabled: true,
})

watch(
  data,
  (s) => {
    if (s) Object.assign(form, s)
  },
  { immediate: true },
)

// Enabled locales for the default-locale select; keep the current value selectable even if disabled.
const localeOptions = computed(() => {
  const items = (locales.value ?? [])
    .filter((l) => l.enabled)
    .map((l) => ({ label: `${l.name} (${l.code})`, value: l.code }))
  if (form.default_locale && !items.some((i) => i.value === form.default_locale)) {
    items.unshift({ label: form.default_locale, value: form.default_locale })
  }
  return items
})

async function onSave() {
  try {
    await save.mutateAsync({ ...form })
    success('General settings saved', 'Changes apply on the next request.')
  } catch (e) {
    notifyError(e, 'Couldn’t save general settings')
  }
}
</script>

<template>
  <UDashboardPanel id="settings-general">
    <template #header>
      <UDashboardNavbar title="General">
        <template #right>
          <UButton icon="i-lucide-save" :loading="save.isLoading.value" @click="onSave">
            Save
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-2xl space-y-6">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-40" />
          <USkeleton class="h-28" />
          <USkeleton class="h-40" />
        </div>

        <template v-else>
          <UCard>
            <template #header><h2 class="font-semibold text-default">Site identity</h2></template>
            <div class="space-y-4">
              <UFormField label="Site name" hint="Shown to admins; the instance display name.">
                <UInput v-model="form.site_name" placeholder="Lemma" class="w-full" />
              </UFormField>
              <UFormField
                label="Site preview URL"
                hint="Base URL of the live site, used for preview / “view live” links."
              >
                <UInput
                  v-model="form.site_preview_url"
                  type="url"
                  placeholder="https://example.com"
                  class="w-full"
                />
              </UFormField>
            </div>
          </UCard>

          <UCard>
            <template #header><h2 class="font-semibold text-default">Localization</h2></template>
            <UFormField
              label="Default locale"
              hint="The default content locale. Manage the enabled list under Languages."
            >
              <USelect v-model="form.default_locale" :items="localeOptions" class="w-full" />
            </UFormField>
          </UCard>

          <UCard>
            <template #header
              ><h2 class="font-semibold text-default">Content delivery</h2></template
            >
            <div class="space-y-4">
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField label="Default items per page" hint="Default page size for delivery.">
                  <UInput
                    v-model.number="form.default_per_page"
                    type="number"
                    :min="1"
                    class="w-full"
                  />
                </UFormField>
                <UFormField label="Max items per page" hint="Hard cap a client can request.">
                  <UInput
                    v-model.number="form.max_per_page"
                    type="number"
                    :min="1"
                    class="w-full"
                  />
                </UFormField>
              </div>
              <UFormField
                label="Cache TTL (seconds)"
                hint="Cache-Control max-age for delivery responses. 0 disables caching."
              >
                <UInput v-model.number="form.cache_ttl" type="number" :min="0" class="w-full" />
              </UFormField>
            </div>
          </UCard>

          <UCard>
            <template #header><h2 class="font-semibold text-default">Feature toggles</h2></template>
            <div class="space-y-4">
              <USwitch
                v-model="form.scheduler_enabled"
                label="Publish scheduler"
                description="Run scheduled publish/unpublish jobs."
              />
              <USwitch
                v-model="form.webhooks_enabled"
                label="Content webhooks"
                description="Dispatch content events to webhook subscriptions (master switch)."
              />
            </div>
          </UCard>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
