<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { AuditLogRow } from '@/queries/audit'
import AuditLogListPane from './components/AuditLogListPane.vue'
import AuditLogDetailPane from './components/AuditLogDetailPane.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const selectedUuid = computed(() => (route.query.entry as string | undefined) || undefined)

function select(row: AuditLogRow) {
  router.replace({ query: { ...route.query, entry: row.uuid } })
}
function clearSelection() {
  const q = { ...route.query }
  delete q.entry
  router.replace({ query: q })
}
</script>

<template>
  <UDashboardPanel id="audit-log" :ui="{ body: 'overflow-hidden' }">
    <template #body>
      <div class="flex h-full min-h-0 p-1">
        <!-- List pane: visible on lg+; on mobile only when nothing is selected. -->
        <div
          class="min-h-0 lg:shrink-0 lg:border-e lg:border-default lg:pe-4"
          :class="selectedUuid ? 'hidden lg:block' : 'block'"
        >
          <AuditLogListPane class="h-full" :selected-uuid="selectedUuid" @select="select" />
        </div>

        <!-- Detail pane: visible on lg+; on mobile only when an entry is selected. -->
        <div
          class="min-w-0 flex-1 flex-col lg:ps-6"
          :class="selectedUuid ? 'flex' : 'hidden lg:flex'"
        >
          <div v-if="!selectedUuid" class="m-auto text-center text-sm text-muted">
            <UIcon name="i-lucide-mouse-pointer-click" class="mx-auto mb-2 size-6" />
            Select an entry to view details
          </div>
          <template v-else>
            <UButton
              class="mb-2 self-start lg:hidden"
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-arrow-left"
              label="Back"
              @click="clearSelection"
            />
            <AuditLogDetailPane :key="selectedUuid" :uuid="selectedUuid" class="min-h-0 flex-1" />
          </template>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
