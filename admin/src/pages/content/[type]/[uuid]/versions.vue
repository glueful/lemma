<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useVersions, useRollback } from '@/queries/versions'
import { runtimeConfig } from '@/runtime/config'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const toast = useToast()
const type = computed(() => String(route.params.type))
const uuid = computed(() => String(route.params.uuid))
const locale = runtimeConfig.defaultLocale

const { data: versions, status } = useVersions(uuid, () => locale)
const rollback = useRollback(uuid.value, locale, type.value)

async function onRollback(versionUuid: string) {
  try {
    await rollback.mutateAsync(versionUuid)
    toast.add({ title: 'Rolled back', color: 'success' })
    router.push(`/content/${type.value}/${uuid.value}`)
  } catch {
    toast.add({ title: 'Rollback failed', color: 'error' })
  }
}
</script>

<template>
  <div class="space-y-4 p-6">
    <header class="flex items-center justify-between">
      <h1 class="text-xl font-semibold text-highlighted">Versions</h1>
      <UButton variant="ghost" icon="i-lucide-arrow-left" :to="`/content/${type}/${uuid}`">
        Back to editor
      </UButton>
    </header>

    <div v-if="status === 'pending'" class="space-y-2">
      <USkeleton v-for="n in 4" :key="n" class="h-10" />
    </div>
    <UEmpty
      v-else-if="!versions?.length"
      icon="i-lucide-history"
      title="No versions yet"
      description="Published changes create versions you can roll back to."
    />
    <ul v-else class="divide-y divide-default rounded-lg border border-default">
      <li v-for="v in versions" :key="v.uuid" class="flex items-center justify-between p-3">
        <div>
          <p class="text-sm font-medium text-default">Version {{ v.version ?? v.uuid }}</p>
          <p class="text-xs text-muted">{{ v.created_at ?? '' }}</p>
        </div>
        <UButton
          size="sm"
          variant="subtle"
          :loading="rollback.isLoading.value"
          @click="onRollback(v.uuid)"
        >
          Restore
        </UButton>
      </li>
    </ul>
  </div>
</template>
