<script setup lang="ts">
import { computed } from 'vue'
import { useAuditLog, auditActionMeta, auditActorName, auditTargetLabel } from '@/queries/audit'

const props = defineProps<{ uuid: string }>()
const { data: row, status } = useAuditLog(() => props.uuid)
const meta = computed(() => (row.value ? auditActionMeta(row.value.action) : null))
const target = computed(() => (row.value ? auditTargetLabel(row.value) : null))
const changeEntries = computed(() => (row.value?.changes ? Object.entries(row.value.changes) : []))

function fmtDateTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(v.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'medium' })
}

function fmtVal(v: unknown): string {
  if (v === null || v === undefined) return '∅'
  return typeof v === 'object' ? JSON.stringify(v) : String(v)
}

function pretty(v: unknown): string {
  return JSON.stringify(v, null, 2)
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col">
    <div v-if="status === 'pending'" class="flex flex-1 items-center justify-center">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <template v-else-if="row && meta">
      <header class="mb-5 flex flex-wrap items-center gap-2">
        <UBadge
          :label="meta.label"
          :color="meta.color"
          :icon="meta.icon"
          variant="subtle"
          size="lg"
        />
        <span class="text-sm text-muted">
          <span class="font-medium text-default">{{ auditActorName(row) }}</span>
          <template v-if="target"> → {{ target }}</template>
        </span>
      </header>

      <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto pe-1">
        <!-- Actor -->
        <section>
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Actor</h3>
          <div class="flex items-center gap-3">
            <UAvatar :text="auditActorName(row).charAt(0).toUpperCase()" size="md" />
            <div class="min-w-0">
              <p class="text-sm font-medium text-default">{{ auditActorName(row) }}</p>
              <p class="truncate text-xs text-muted">
                <code v-if="row.actor_uuid">{{ row.actor_uuid }}</code>
                <span v-else>System / anonymous</span>
              </p>
            </div>
          </div>
        </section>

        <!-- Activity details -->
        <section>
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">
            Activity details
          </h3>
          <dl class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
            <div>
              <dt class="text-xs text-muted">Category</dt>
              <dd class="text-sm capitalize text-default">{{ row.category }}</dd>
            </div>
            <div>
              <dt class="text-xs text-muted">Action</dt>
              <dd class="text-sm text-default">
                <code>{{ row.action }}</code>
              </dd>
            </div>
            <div v-if="target">
              <dt class="text-xs text-muted">Target</dt>
              <dd class="text-sm text-default">{{ target }}</dd>
            </div>
            <div>
              <dt class="text-xs text-muted">Timestamp</dt>
              <dd class="text-sm text-default">{{ fmtDateTime(row.occurred_at) }}</dd>
            </div>
            <div v-if="row.context?.ip">
              <dt class="text-xs text-muted">IP address</dt>
              <dd class="text-sm text-default">
                <code>{{ row.context.ip }}</code>
              </dd>
            </div>
            <div v-if="row.context?.request_id">
              <dt class="text-xs text-muted">Request ID</dt>
              <dd class="truncate text-sm text-default">
                <code>{{ row.context.request_id }}</code>
              </dd>
            </div>
          </dl>
          <div v-if="row.context?.user_agent" class="mt-3">
            <p class="text-xs text-muted">User agent</p>
            <p class="break-all text-sm text-default">{{ row.context.user_agent }}</p>
          </div>
        </section>

        <!-- Field changes (updates) -->
        <section v-if="changeEntries.length">
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Changes</h3>
          <div class="overflow-hidden rounded-lg border border-default">
            <div
              v-for="[field, change] in changeEntries"
              :key="field"
              class="flex flex-col gap-1 border-b border-default px-3 py-2 last:border-b-0 sm:flex-row sm:items-center sm:gap-3"
            >
              <span class="w-40 shrink-0 text-xs font-medium text-muted">{{ field }}</span>
              <div class="flex min-w-0 flex-1 items-center gap-2 text-sm">
                <span class="truncate text-error line-through">{{ fmtVal(change.from) }}</span>
                <UIcon name="i-lucide-arrow-right" class="size-3 shrink-0 text-muted" />
                <span class="truncate text-success">{{ fmtVal(change.to) }}</span>
              </div>
            </div>
          </div>
        </section>

        <!-- Full raw context payload, as formatted JSON -->
        <section v-if="row.context">
          <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Metadata</h3>
          <pre class="overflow-x-auto rounded-lg bg-elevated p-3 text-xs text-default">{{
            pretty(row.context)
          }}</pre>
        </section>
      </div>
    </template>

    <UEmpty
      v-else
      icon="i-lucide-file-question"
      title="Entry not found"
      description="This audit entry may have been pruned."
    />
  </div>
</template>
