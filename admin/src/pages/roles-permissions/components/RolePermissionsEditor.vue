<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  usePermissions,
  useRolePermissions,
  useRolePermissionMutations,
  type Permission,
  type Role,
} from '@/queries/rbac'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ role: Role }>()

const { success, error: notifyError } = useNotify()
const { data: allPerms, status: permsStatus } = usePermissions()
const { data: current, status: currentStatus } = useRolePermissions(() => props.role.uuid)
const { replace } = useRolePermissionMutations(props.role.uuid)

const loading = computed(() => permsStatus.value === 'pending' || currentStatus.value === 'pending')

// The working set of granted permission UUIDs, seeded from the role's current grants. Saving PUTs
// the whole set (the sync endpoint), so the role ends up with exactly what's on the Assigned side.
const assignedIds = ref<Set<string>>(new Set())
const selectedAvailable = ref<Set<string>>(new Set())
const selectedAssigned = ref<Set<string>>(new Set())
const availableSearch = ref('')
const assignedSearch = ref('')

watch(
  current,
  (c) => {
    if (c) assignedIds.value = new Set(c)
  },
  { immediate: true },
)

function permLabel(p: Permission): string {
  return p.slug ?? p.name ?? p.uuid
}
function matches(p: Permission, term: string): boolean {
  if (!term) return true
  const t = term.toLowerCase()
  return (
    permLabel(p).toLowerCase().includes(t) ||
    (p.name ?? '').toLowerCase().includes(t) ||
    (p.category ?? '').toLowerCase().includes(t)
  )
}

const availablePerms = computed(() =>
  (allPerms.value ?? [])
    .filter((p) => !assignedIds.value.has(p.uuid))
    .filter((p) => matches(p, availableSearch.value)),
)
const assignedPerms = computed(() =>
  (allPerms.value ?? [])
    .filter((p) => assignedIds.value.has(p.uuid))
    .filter((p) => matches(p, assignedSearch.value)),
)

function toggleAvailable(id: string) {
  const s = new Set(selectedAvailable.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  selectedAvailable.value = s
}
function toggleAssigned(id: string) {
  const s = new Set(selectedAssigned.value)
  if (s.has(id)) s.delete(id)
  else s.add(id)
  selectedAssigned.value = s
}
function selectAllAvailable() {
  selectedAvailable.value = new Set(availablePerms.value.map((p) => p.uuid))
}
function selectAllAssigned() {
  selectedAssigned.value = new Set(assignedPerms.value.map((p) => p.uuid))
}

function moveToAssigned() {
  const next = new Set(assignedIds.value)
  for (const id of selectedAvailable.value) next.add(id)
  assignedIds.value = next
  selectedAvailable.value = new Set()
}
function moveAllToAssigned() {
  const next = new Set(assignedIds.value)
  for (const p of availablePerms.value) next.add(p.uuid)
  assignedIds.value = next
  selectedAvailable.value = new Set()
}
function moveToAvailable() {
  const next = new Set(assignedIds.value)
  for (const id of selectedAssigned.value) next.delete(id)
  assignedIds.value = next
  selectedAssigned.value = new Set()
}
function moveAllToAvailable() {
  const next = new Set(assignedIds.value)
  for (const p of assignedPerms.value) next.delete(p.uuid)
  assignedIds.value = next
  selectedAssigned.value = new Set()
}

async function onSave() {
  try {
    await replace.mutateAsync([...assignedIds.value])
    success(
      'Permissions updated',
      `“${props.role.name}” now grants ${assignedIds.value.size} permission(s).`,
    )
  } catch (e) {
    notifyError(e, 'Couldn’t update permissions')
  }
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-4">
    <div v-if="loading" class="flex flex-1 items-center justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <template v-else>
      <div class="flex min-h-0 flex-1 flex-col gap-3 xl:grid xl:grid-cols-[1fr_auto_1fr]">
        <!-- Available (not yet granted) -->
        <div class="flex min-h-0 flex-col rounded-xl border border-default">
          <div class="flex items-center justify-between border-b border-default px-3 py-2">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-highlighted">Available</span>
              <UBadge
                :label="String(availablePerms.length)"
                color="neutral"
                variant="subtle"
                size="xs"
              />
            </div>
            <UButton
              label="Select all"
              color="primary"
              variant="link"
              size="xs"
              :disabled="!availablePerms.length"
              @click="selectAllAvailable"
            />
          </div>
          <div class="px-3 py-2">
            <UInput
              v-model="availableSearch"
              icon="i-lucide-search"
              placeholder="Search…"
              size="sm"
              class="w-full"
            />
          </div>
          <div class="flex-1 overflow-y-auto px-1 pb-2">
            <div
              v-if="!availablePerms.length"
              class="flex items-center justify-center py-8 text-xs text-muted"
            >
              All permissions assigned
            </div>
            <button
              v-for="perm in availablePerms"
              :key="perm.uuid"
              type="button"
              class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors"
              :class="
                selectedAvailable.has(perm.uuid)
                  ? 'bg-primary/10 text-primary'
                  : 'text-toned hover:bg-elevated'
              "
              @click="toggleAvailable(perm.uuid)"
            >
              <span
                class="size-2 shrink-0 rounded-full"
                :class="selectedAvailable.has(perm.uuid) ? 'bg-primary' : 'bg-accented'"
              />
              <code class="truncate">{{ permLabel(perm) }}</code>
            </button>
          </div>
        </div>

        <!-- Transfer controls -->
        <div class="flex flex-row items-center justify-center gap-2 xl:flex-col">
          <UButton
            icon="i-lucide-chevron-right"
            color="neutral"
            variant="outline"
            size="xs"
            aria-label="Assign selected"
            :disabled="!selectedAvailable.size"
            @click="moveToAssigned"
          />
          <UButton
            icon="i-lucide-chevrons-right"
            color="neutral"
            variant="outline"
            size="xs"
            aria-label="Assign all"
            :disabled="!availablePerms.length"
            @click="moveAllToAssigned"
          />
          <UButton
            icon="i-lucide-chevron-left"
            color="neutral"
            variant="outline"
            size="xs"
            aria-label="Remove selected"
            :disabled="!selectedAssigned.size"
            @click="moveToAvailable"
          />
          <UButton
            icon="i-lucide-chevrons-left"
            color="neutral"
            variant="outline"
            size="xs"
            aria-label="Remove all"
            :disabled="!assignedIds.size"
            @click="moveAllToAvailable"
          />
        </div>

        <!-- Assigned (current grants) -->
        <div class="flex min-h-0 flex-col rounded-xl border border-default">
          <div class="flex items-center justify-between border-b border-default px-3 py-2">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-highlighted">Assigned</span>
              <UBadge
                :label="String(assignedIds.size)"
                color="primary"
                variant="subtle"
                size="xs"
              />
            </div>
            <UButton
              label="Select all"
              color="primary"
              variant="link"
              size="xs"
              :disabled="!assignedPerms.length"
              @click="selectAllAssigned"
            />
          </div>
          <div class="px-3 py-2">
            <UInput
              v-model="assignedSearch"
              icon="i-lucide-search"
              placeholder="Search…"
              size="sm"
              class="w-full"
            />
          </div>
          <div class="flex-1 overflow-y-auto px-1 pb-2">
            <div
              v-if="!assignedIds.size"
              class="flex items-center justify-center py-8 text-xs text-muted"
            >
              No permissions assigned
            </div>
            <button
              v-for="perm in assignedPerms"
              :key="perm.uuid"
              type="button"
              class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors"
              :class="
                selectedAssigned.has(perm.uuid)
                  ? 'bg-primary/10 text-primary'
                  : 'text-toned hover:bg-elevated'
              "
              @click="toggleAssigned(perm.uuid)"
            >
              <span
                class="size-2 shrink-0 rounded-full"
                :class="selectedAssigned.has(perm.uuid) ? 'bg-primary' : 'bg-primary/40'"
              />
              <code class="truncate">{{ permLabel(perm) }}</code>
            </button>
          </div>
        </div>
      </div>

      <div class="flex w-full items-center justify-between gap-2">
        <p class="text-xs text-muted">Click items to select, then use the arrows to move them.</p>
        <UButton
          icon="i-lucide-check"
          label="Save permissions"
          :loading="replace.isLoading.value"
          @click="onSave"
        />
      </div>
    </template>
  </div>
</template>
