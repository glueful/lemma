<script setup lang="ts">
import { computed } from 'vue'
import { useTimeAgo } from '@vueuse/core'
import {
  auditActionMeta,
  auditActorName,
  auditTargetLabel,
  type AuditLogRow,
} from '@/queries/audit'

const props = defineProps<{ row: AuditLogRow; selected?: boolean }>()
defineEmits<{ select: [] }>()

const meta = computed(() => auditActionMeta(props.row.action))
const target = computed(() => auditTargetLabel(props.row))
const when = useTimeAgo(computed(() => new Date((props.row.occurred_at || '').replace(' ', 'T'))))
</script>

<template>
  <button
    type="button"
    class="flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left transition-colors"
    :class="selected ? 'bg-elevated' : 'hover:bg-elevated/50'"
    @click="$emit('select')"
  >
    <UAvatar :text="auditActorName(row).charAt(0).toUpperCase()" size="sm" class="mt-0.5" />
    <div class="min-w-0 flex-1">
      <div class="flex items-center gap-2">
        <span class="truncate text-sm font-medium text-default">{{ auditActorName(row) }}</span>
        <UBadge
          :label="meta.label"
          :color="meta.color"
          :icon="meta.icon"
          variant="subtle"
          size="xs"
          class="shrink-0"
        />
      </div>
      <p class="truncate text-xs text-muted">
        <span v-if="target">{{ target }}</span>
        <span v-else class="capitalize">{{ row.category }}</span>
      </p>
    </div>
    <span class="shrink-0 text-xs text-muted">{{ when }}</span>
  </button>
</template>
