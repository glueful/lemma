<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useMediaItem, type MediaItem } from '@/queries/media'
import MediaListPane from './components/MediaListPane.vue'
import MediaPreview from './components/MediaPreview.vue'
import MediaPanel from './components/MediaPanel.vue'

definePage({ meta: { requiresAuth: true } })

const route = useRoute()
const router = useRouter()
const selectedUuid = computed(() => (route.query.item as string | undefined) || undefined)
const { data: detail } = useMediaItem(() => selectedUuid.value)

function select(m: MediaItem) {
  router.replace({ query: { ...route.query, item: m.uuid } })
}
function clearSelection() {
  const q = { ...route.query }
  delete q.item
  router.replace({ query: q })
}
// After a delete, the panel closes its own modal; return to the media list.
function onDeleted() {
  router.push('/media')
}
</script>

<template>
  <UDashboardPanel id="media" :ui="{ body: 'overflow-hidden' }">
    <template #body>
      <div class="flex h-full min-h-0">
        <!-- List pane: always on lg+; on mobile only when nothing is selected. -->
        <div
          class="min-h-0 border-e border-default p-3 lg:shrink-0 lg:pe-4"
          :class="selectedUuid ? 'hidden lg:block' : 'block w-full lg:w-auto'"
        >
          <MediaListPane class="h-full" :selected-uuid="selectedUuid" @select="select" />
        </div>

        <!-- Detail region: preview + metadata panel (stacked below lg, side-by-side on lg+). -->
        <div
          class="min-w-0 flex-1 flex-col lg:flex-row"
          :class="selectedUuid ? 'flex' : 'hidden lg:flex'"
        >
          <div class="flex min-h-0 flex-1 flex-col">
            <UButton
              v-if="selectedUuid"
              class="m-2 self-start lg:hidden"
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-arrow-left"
              label="Back"
              @click="clearSelection"
            />
            <MediaPreview :item="detail" class="min-h-0 flex-1" />
          </div>

          <div
            v-if="detail"
            class="shrink-0 overflow-y-auto border-default p-4 lg:w-90 lg:border-s"
          >
            <MediaPanel :key="detail.uuid" :item="detail" @deleted="onDeleted" />
          </div>
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
