<script setup lang="ts">
import { computed, reactive } from 'vue'
import { useRoute } from 'vue-router'
import { useRedirects, useRedirectMutations } from '@/queries/redirects'
import { runtimeConfig } from '@/runtime/config'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const { success, error: notifyError } = useNotify()
const type = computed(() => String(route.params.type))

const { data: redirects, status } = useRedirects(type)
const { create, remove } = useRedirectMutations(type.value)

const statusOptions = ['301', '302', '308']
const form = reactive({ source_slug: '', url: '', status: '301' })

async function onCreate() {
  if (!form.source_slug || !form.url) return
  try {
    await create.mutateAsync({
      locale: runtimeConfig.defaultLocale,
      source_slug: form.source_slug,
      status: Number(form.status),
      url: form.url,
    })
    form.source_slug = ''
    form.url = ''
    form.status = '301'
    success('Redirect created')
  } catch (e) {
    notifyError(e, 'Couldn’t create redirect')
  }
}

async function onDelete(uuid: string) {
  try {
    await remove.mutateAsync(uuid)
    success('Redirect removed')
  } catch (e) {
    notifyError(e, 'Couldn’t remove redirect')
  }
}
</script>

<template>
  <UDashboardPanel id="content-redirects">
    <template #header>
      <UDashboardNavbar>
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            :to="`/content/${type}`"
            aria-label="Back to entries"
          />
        </template>
        <template #title
          ><span class="capitalize">{{ type }} redirects</span></template
        >
      </UDashboardNavbar>
    </template>

    <template #body>
      <UCard>
        <template #header><h2 class="font-semibold text-default">New redirect</h2></template>
        <div class="flex flex-wrap items-end gap-2">
          <UFormField label="Source slug">
            <UInput v-model="form.source_slug" placeholder="old-path" />
          </UFormField>
          <UFormField label="Target URL" class="flex-1">
            <UInput v-model="form.url" placeholder="/new-path or https://…" class="w-full" />
          </UFormField>
          <UFormField label="Status"
            ><USelect v-model="form.status" :items="statusOptions"
          /></UFormField>
          <UButton :loading="create.isLoading.value" @click="onCreate">Add</UButton>
        </div>
      </UCard>

      <div v-if="status === 'pending'" class="space-y-2">
        <USkeleton v-for="n in 3" :key="n" class="h-10" />
      </div>
      <UEmpty
        v-else-if="!redirects?.length"
        icon="i-lucide-signpost"
        title="No redirects"
        description="Add a redirect to forward an old slug to a new target."
      />
      <ul v-else class="divide-y divide-default rounded-lg border border-default">
        <li
          v-for="r in redirects"
          :key="r.uuid"
          class="flex items-center justify-between p-3 text-sm"
        >
          <span class="text-default">
            {{ r.source_slug }} <span class="text-muted">→ {{ r.status }}</span>
            <UBadge
              v-if="r.target_state"
              size="sm"
              :color="r.target_state === 'live' ? 'success' : 'error'"
              variant="subtle"
            >
              {{ r.target_state }}
            </UBadge>
          </span>
          <UButton
            color="error"
            variant="ghost"
            size="xs"
            icon="i-lucide-trash-2"
            :loading="remove.isLoading.value"
            @click="onDelete(r.uuid)"
          />
        </li>
      </ul>
    </template>
  </UDashboardPanel>
</template>
