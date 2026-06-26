<script setup lang="ts">
import { ref } from 'vue'
import InstalledExtensions from './components/InstalledExtensions.vue'
import BrowseExtensions from './components/BrowseExtensions.vue'

definePage({ meta: { requiresAuth: true } })

const tab = ref<'installed' | 'browse'>('installed')
const tabItems = [
  { label: 'Installed', value: 'installed', icon: 'i-lucide-package-check' },
  { label: 'Browse', value: 'browse', icon: 'i-lucide-store' },
]
</script>

<template>
  <UDashboardPanel id="extensions" :ui="{ body: 'overflow-hidden' }">
    <template #body>
      <div class="flex h-full min-h-0 flex-col p-1">
        <div class="mb-3 shrink-0">
          <h1 class="mb-3 text-lg font-semibold text-highlighted">Extensions</h1>
          <UTabs v-model="tab" :items="tabItems" variant="link" :content="false" />
        </div>
        <div class="min-h-0 flex-1">
          <InstalledExtensions v-if="tab === 'installed'" />
          <BrowseExtensions v-else />
        </div>
      </div>
    </template>
  </UDashboardPanel>
</template>
