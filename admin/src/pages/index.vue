<script setup lang="ts">
import { useContentTypes } from '@/queries/contentTypes'
import { useSessionStore } from '@/stores/session'

definePage({ meta: { requiresAuth: true } })

const session = useSessionStore()
const { data: contentTypes, isLoading } = useContentTypes()
</script>

<template>
  <UDashboardPanel id="home">
    <template #header>
      <UDashboardNavbar title="Home" />
    </template>

    <template #body>
      <header>
        <h1 class="text-xl font-semibold text-highlighted">
          Welcome{{ session.user?.email ? `, ${session.user.email}` : '' }}
        </h1>
        <p class="text-sm text-muted">Pick a content type to start authoring.</p>
      </header>

      <div v-if="isLoading" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <USkeleton v-for="n in 3" :key="n" class="h-24" />
      </div>

      <!-- A fresh install seeds a Pages type, so this empty state is the cold-start fallback. -->
      <UEmpty
        v-else-if="!contentTypes?.length"
        icon="i-lucide-layers"
        title="No content types yet"
        description="Define your first content type to start authoring."
      />

      <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <UCard
          v-for="ct in contentTypes"
          :key="ct.uuid"
          class="cursor-pointer transition hover:ring-primary"
          @click="$router.push(`/content/${ct.slug}`)"
        >
          <div class="flex items-center gap-3">
            <UIcon name="i-lucide-file-text" class="size-5 text-muted" />
            <div>
              <p class="font-medium text-default">{{ ct.name }}</p>
              <p class="text-xs text-muted">{{ ct.slug }}</p>
            </div>
          </div>
        </UCard>
      </div>
    </template>
  </UDashboardPanel>
</template>
