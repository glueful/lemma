<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRoles, type Role } from '@/queries/rbac'
import RoleListItem from './RoleListItem.vue'

const props = defineProps<{ selectedUuid?: string }>()
const emit = defineEmits<{ select: [role: Role]; create: [] }>()

const { data: roles, status } = useRoles()
const search = ref('')

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase()
  const list = roles.value ?? []
  if (!term) return list
  return list.filter(
    (r) =>
      r.name.toLowerCase().includes(term) ||
      r.slug.toLowerCase().includes(term) ||
      (r.description ?? '').toLowerCase().includes(term),
  )
})
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-85 lg:shrink-0">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-highlighted">Roles</h2>
      <UButton icon="i-lucide-plus" size="sm" aria-label="New role" @click="emit('create')" />
    </div>

    <UInput v-model="search" icon="i-lucide-search" placeholder="Search roles…" class="w-full" />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!filtered.length"
        icon="i-lucide-shield"
        title="No roles"
        :description="search ? 'No roles match your search.' : 'Create a role to get started.'"
      />
      <div v-else class="flex flex-col gap-0.5">
        <RoleListItem
          v-for="r in filtered"
          :key="r.uuid"
          :role="r"
          :selected="r.uuid === props.selectedUuid"
          @select="emit('select', r)"
        />
      </div>
    </div>

    <div
      v-if="(roles?.length ?? 0) > 0"
      class="flex items-center justify-between gap-2 border-t border-default pt-3 text-muted"
    >
      <span class="text-xs font-medium uppercase tracking-wide">{{ filtered.length }} roles</span>
    </div>
  </div>
</template>
