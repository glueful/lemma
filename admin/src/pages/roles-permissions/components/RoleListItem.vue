<script setup lang="ts">
import type { Role } from '@/queries/rbac'

defineProps<{ role: Role; selected?: boolean }>()
defineEmits<{ select: [] }>()
</script>

<template>
  <button
    type="button"
    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors"
    :class="selected ? 'bg-elevated' : 'hover:bg-elevated/50'"
    @click="$emit('select')"
  >
    <div class="min-w-0 flex-1">
      <p class="truncate text-sm font-medium text-default">{{ role.name }}</p>
      <code class="truncate text-xs text-muted">{{ role.slug }}</code>
    </div>
    <UBadge
      v-if="role.level != null"
      :label="`L${role.level}`"
      color="neutral"
      variant="subtle"
      size="xs"
      class="shrink-0"
    />
  </button>
</template>
