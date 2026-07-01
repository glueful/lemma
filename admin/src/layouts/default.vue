<script setup lang="ts">
import { computed } from 'vue'
import { open, useVisibleNav } from '../navigation/sidebar'
import { registerCoreModule } from '@/registry/coreModule'
import { registerCollectionsModule } from '@/registry/collectionsModule'
import { registerAnalyticsModule } from '@/registry/analyticsModule'
import { useCapabilitiesStore } from '@/stores/capabilities'
import { useContentTypes } from '@/queries/contentTypes'

registerCoreModule()
registerCollectionsModule()
registerAnalyticsModule()
useCapabilitiesStore().ensureLoaded() // post-auth: this layout only renders for authenticated users

const nav = useVisibleNav()
const { data: contentTypes } = useContentTypes()

// nav.value[0] = main nav; inject live content types into the Content section's children (unchanged behavior).
const mainItems = computed(() =>
  nav.value[0].map((item) =>
    item.label === 'Content'
      ? {
          ...item,
          children: (contentTypes.value ?? []).map((ct) => ({
            label: ct.name ?? ct.slug ?? 'Untitled',
            icon: 'i-lucide-file-text',
            to: `/content/${ct.slug}`,
          })),
        }
      : item,
  ),
)
const utilityItems = computed(() => nav.value[1])
</script>

<template>
  <UDashboardGroup unit="rem" storage="local">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      :min-size="16"
      :default-size="16"
      :max-size="16"
      class="bg-elevated/25 border-r-0"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <AppLogo v-if="!collapsed" class="w-auto h-10 shrink-0" :show-text="true" />
        <UDashboardSidebarCollapse :class="collapsed ? 'mx-auto' : 'ms-auto'" />
      </template>

      <template #default="{ collapsed }">
        <UNavigationMenu
          :collapsed="collapsed"
          :items="mainItems"
          orientation="vertical"
          tooltip
          popover
          :ui="{ link: 'my-1.5' }"
        />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="utilityItems"
          orientation="vertical"
          tooltip
          class="mt-auto"
          :ui="{ link: 'my-1.5' }"
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>
    <div
      class="flex-1 flex flex-col min-w-0 min-h-0 bg-white rounded-2xl m-3 ring ring-default dark:bg-default"
    >
      <RouterView />
    </div>
  </UDashboardGroup>
</template>
