<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSubscription, type WebhookSubscription } from '@/queries/webhooks'
import WebhookListPane from './components/WebhookListPane.vue'
import WebhookDetailPane from './components/WebhookDetailPane.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const selectedUuid = computed(() => (route.query.hook as string | undefined) || undefined)
const { data: detail } = useSubscription(() => selectedUuid.value)

function select(s: WebhookSubscription) {
  router.replace({ query: { ...route.query, hook: s.uuid } })
}
function clearSelection() {
  const q = { ...route.query }
  delete q.hook
  router.replace({ query: q })
}
</script>

<template>
  <UDashboardPanel id="webhooks" :ui="{ body: 'overflow-hidden' }">
    <template #body>
      <div class="flex h-full min-h-0">
        <!-- List pane: always on lg+; on mobile only when nothing is selected. -->
        <div
          class="min-h-0 border-e border-default p-3 lg:shrink-0 lg:pe-4"
          :class="selectedUuid ? 'hidden lg:block' : 'block w-full lg:w-auto'"
        >
          <WebhookListPane
            class="h-full"
            :selected-uuid="selectedUuid"
            @select="select"
            @created="select"
          />
        </div>

        <!-- Detail pane. -->
        <div class="min-w-0 flex-1" :class="selectedUuid ? 'block' : 'hidden lg:block'">
          <div v-if="detail" class="h-full overflow-y-auto p-4 lg:p-6">
            <UButton
              class="mb-3 lg:hidden"
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-arrow-left"
              label="Back"
              @click="clearSelection"
            />
            <div class="mx-auto max-w-xl">
              <WebhookDetailPane :key="detail.uuid" :item="detail" @deleted="clearSelection" />
            </div>
          </div>
          <div v-else class="hidden h-full items-center justify-center lg:flex">
            <div class="flex flex-col items-center gap-2 text-muted">
              <UIcon name="i-lucide-webhook" class="size-8" />
              <p class="text-sm">Select a webhook to view its details.</p>
            </div>
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
