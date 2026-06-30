<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import CollectionDataPane from '../../components/CollectionDataPane.vue'

const route = useRoute()
const name = computed(() => String(route.params.name))
</script>

<template>
  <UDashboardPanel id="collection-data">
    <template #header>
      <UDashboardNavbar :title="`${name} · data`">
        <template #leading>
          <UButton
            variant="ghost"
            color="neutral"
            icon="i-lucide-arrow-left"
            to="/collections"
            aria-label="Back to collections"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <CollectionDataPane :collection-name="name" class="h-full" />
    </template>
  </UDashboardPanel>
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: lemma.collections
</route>
