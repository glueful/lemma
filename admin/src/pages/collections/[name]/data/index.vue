<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CollectionDataPane from '../../components/CollectionDataPane.vue'
import CollectionEditSlideover from '../../components/CollectionEditSlideover.vue'

const route = useRoute()
const router = useRouter()
const name = computed(() => String(route.params.name))

const showEdit = ref(false)
function onDropped() {
  router.push('/collections')
}
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
      <CollectionDataPane :collection-name="name" class="h-full" @edit-schema="showEdit = true" />
    </template>
  </UDashboardPanel>

  <CollectionEditSlideover v-model:open="showEdit" :name="name" @dropped="onDropped" />
</template>

<route lang="yaml">
meta:
  requiresAuth: true
  requiresCapability: lemma.collections
</route>
