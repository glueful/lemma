<script setup lang="ts">
import { userDisplayName, type UserRow } from '@/queries/users'

defineProps<{ user: UserRow; selected?: boolean }>()
defineEmits<{ select: [] }>()
</script>

<template>
  <button
    type="button"
    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors"
    :class="selected ? 'bg-elevated' : 'hover:bg-elevated/50'"
    @click="$emit('select')"
  >
    <UAvatar :text="(user.email || user.username || '?').charAt(0).toUpperCase()" size="md" />
    <div class="min-w-0 flex-1">
      <p class="truncate text-sm font-medium text-default">{{ userDisplayName(user) }}</p>
      <p class="truncate text-xs text-muted">{{ user.email ?? '—' }}</p>
    </div>
    <div class="flex shrink-0 items-center gap-1.5">
      <span
        class="size-2 rounded-full"
        :class="user.status === 'active' ? 'bg-success' : 'bg-muted'"
        :title="user.status ?? 'active'"
      />
    </div>
  </button>
</template>
