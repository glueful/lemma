<script setup lang="ts">
import { computed } from 'vue'
import { open, items } from '../navigation/sidebar'
import { useContentTypes } from '@/queries/contentTypes'

// Content types are fetched live; injected as the Content section's children (the sidebar.ts
// entry ships with empty children — see ADMIN_IA.md).
const { data: contentTypes } = useContentTypes()

const mainItems = computed(() =>
  items[0].map((item) =>
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
          :items="items[1]"
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
    <div class="flex-1 flex flex-col min-w-0 min-h-0 bg-white rounded-2xl m-3 ring ring-default dark:bg-default">
      <RouterView />
    </div>
  </UDashboardGroup>
</template>
