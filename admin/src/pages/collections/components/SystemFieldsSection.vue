<script setup lang="ts">
// Every collection table gets these columns automatically (SchemaMaterializer::createBaseTable).
// They are reserved server-side and not editable here — shown so authors know what they already get.
const SYSTEM_FIELDS = [
  { name: 'id', badge: 'Auto', icon: 'i-lucide-key', note: 'Primary key' },
  { name: 'uuid', badge: 'UUID', icon: 'i-lucide-fingerprint', note: 'Public identifier' },
  {
    name: 'created_at',
    badge: 'Timestamp',
    icon: 'i-lucide-calendar-clock',
    note: 'Set on create',
  },
  {
    name: 'updated_at',
    badge: 'Timestamp',
    icon: 'i-lucide-calendar-clock',
    note: 'Set on update',
  },
]
</script>

<template>
  <div class="space-y-2">
    <p class="text-xs font-medium uppercase tracking-wide text-muted">System fields</p>
    <div class="divide-y divide-default rounded-md border border-default bg-elevated/30">
      <div
        v-for="f in SYSTEM_FIELDS"
        :key="f.name"
        class="flex items-center gap-2 px-3 py-2"
        data-test="system-field"
      >
        <UBadge color="neutral" variant="subtle" size="sm" :icon="f.icon">{{ f.badge }}</UBadge>
        <span class="flex-1 truncate text-sm font-medium text-default">{{ f.name }}</span>
        <span class="hidden text-xs text-muted sm:inline">{{ f.note }}</span>
        <UIcon name="i-lucide-lock" class="size-3.5 text-dimmed" />
      </div>
    </div>
  </div>
</template>
