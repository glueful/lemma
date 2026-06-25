<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  usePermissions,
  useUserPermissions,
  useUserRolePermissions,
  useUserPermissionMutations,
  type Permission,
} from '@/queries/rbac'
import { userDisplayName, type UserRow } from '@/queries/users'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ user: UserRow }>()

const { success, error: notifyError } = useNotify()
const { data: allPerms, status: permsStatus } = usePermissions()
const { data: current, status: currentStatus } = useUserPermissions(() => props.user.uuid)
const { data: roleSlugs, status: roleStatus } = useUserRolePermissions(() => props.user.uuid)
const { save } = useUserPermissionMutations(props.user.uuid)

const loading = computed(
  () =>
    permsStatus.value === 'pending' ||
    currentStatus.value === 'pending' ||
    roleStatus.value === 'pending',
)

// Permissions the user already inherits from their roles — hidden from the picker so admins only
// grant what a role doesn't already provide.
const roleGranted = computed(() => new Set(roleSlugs.value ?? []))

// Working set of granted permission SLUGS (the batch endpoints key off slug), seeded from the user's
// current DIRECT grants. On save we diff this against the original and batch-assign/revoke the delta.
const assigned = ref<Set<string>>(new Set())
const selectedAvailable = ref<Set<string>>(new Set())
const selectedAssigned = ref<Set<string>>(new Set())
const availableSearch = ref('')
const assignedSearch = ref('')

watch(
  current,
  (c) => {
    if (c) assigned.value = new Set(c)
  },
  { immediate: true },
)

function slugOf(p: Permission): string {
  return p.slug ?? ''
}
function matches(p: Permission, term: string): boolean {
  if (!term) return true
  const t = term.toLowerCase()
  return (
    slugOf(p).toLowerCase().includes(t) ||
    (p.name ?? '').toLowerCase().includes(t) ||
    (p.category ?? '').toLowerCase().includes(t)
  )
}

const availablePerms = computed(() =>
  (allPerms.value ?? [])
    // Exclude direct grants AND permissions already inherited from the user's roles.
    .filter(
      (p) =>
        slugOf(p) !== '' && !assigned.value.has(slugOf(p)) && !roleGranted.value.has(slugOf(p)),
    )
    .filter((p) => matches(p, availableSearch.value)),
)
const assignedPerms = computed(() =>
  (allPerms.value ?? [])
    .filter((p) => slugOf(p) !== '' && assigned.value.has(slugOf(p)))
    .filter((p) => matches(p, assignedSearch.value)),
)
// Read-only group: permissions the user inherits from their roles (can't be removed here).
const rolePerms = computed(() =>
  (allPerms.value ?? [])
    .filter((p) => slugOf(p) !== '' && roleGranted.value.has(slugOf(p)))
    .filter((p) => matches(p, assignedSearch.value)),
)

function toggleAvailable(slug: string) {
  const s = new Set(selectedAvailable.value)
  if (s.has(slug)) s.delete(slug)
  else s.add(slug)
  selectedAvailable.value = s
}
function toggleAssigned(slug: string) {
  const s = new Set(selectedAssigned.value)
  if (s.has(slug)) s.delete(slug)
  else s.add(slug)
  selectedAssigned.value = s
}
function selectAllAvailable() {
  selectedAvailable.value = new Set(availablePerms.value.map(slugOf))
}
function selectAllAssigned() {
  selectedAssigned.value = new Set(assignedPerms.value.map(slugOf))
}

function moveToAssigned() {
  const next = new Set(assigned.value)
  for (const slug of selectedAvailable.value) next.add(slug)
  assigned.value = next
  selectedAvailable.value = new Set()
}
function moveAllToAssigned() {
  const next = new Set(assigned.value)
  for (const p of availablePerms.value) next.add(slugOf(p))
  assigned.value = next
  selectedAvailable.value = new Set()
}
function moveToAvailable() {
  const next = new Set(assigned.value)
  for (const slug of selectedAssigned.value) next.delete(slug)
  assigned.value = next
  selectedAssigned.value = new Set()
}
function moveAllToAvailable() {
  const next = new Set(assigned.value)
  for (const p of assignedPerms.value) next.delete(slugOf(p))
  assigned.value = next
  selectedAssigned.value = new Set()
}

async function onSave() {
  const before = new Set(current.value ?? [])
  const add = [...assigned.value].filter((s) => !before.has(s))
  const remove = [...before].filter((s) => !assigned.value.has(s))
  if (add.length === 0 && remove.length === 0) {
    return
  }
  try {
    await save.mutateAsync({ add, remove })
    success(
      'Permissions updated',
      `“${userDisplayName(props.user)}” now has ${assigned.value.size} direct permission(s).`,
    )
  } catch (e) {
    notifyError(e, 'Couldn’t update permissions')
  }
}
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-4">
    <div v-if="loading" class="flex items-center justify-center py-16">
      <UIcon name="i-lucide-loader-circle" class="size-6 animate-spin text-muted" />
    </div>

    <template v-else>
      <div class="flex min-h-0 flex-1 flex-col gap-3 xl:flex-row">
        <!-- Available (not directly granted) -->
        <div class="flex min-h-0 flex-1 flex-col rounded-xl border border-default">
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
              :key="slugOf(perm)"
              type="button"
              class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors"
              :class="
                selectedAvailable.has(slugOf(perm))
                  ? 'bg-primary/10 text-primary'
                  : 'text-toned hover:bg-elevated'
              "
              @click="toggleAvailable(slugOf(perm))"
            >
              <span
                class="size-2 shrink-0 rounded-full"
                :class="selectedAvailable.has(slugOf(perm)) ? 'bg-primary' : 'bg-accented'"
              />
              <code class="truncate">{{ slugOf(perm) }}</code>
            </button>
          </div>
        </div>

        <!-- Transfer controls -->
        <div class="flex shrink-0 flex-row items-center justify-center gap-2 xl:flex-col">
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
            :disabled="!assigned.size"
            @click="moveAllToAvailable"
          />
        </div>

        <!-- Assigned (direct grants) -->
        <div class="flex min-h-0 flex-1 flex-col rounded-xl border border-default">
          <div class="flex items-center justify-between border-b border-default px-3 py-2">
            <div class="flex items-center gap-2">
              <span class="text-sm font-medium text-highlighted">Assigned</span>
              <UBadge :label="String(assigned.size)" color="primary" variant="subtle" size="xs" />
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
              v-if="!assigned.size && !rolePerms.length"
              class="flex items-center justify-center py-8 text-xs text-muted"
            >
              No permissions
            </div>

            <!-- From roles: inherited, read-only (managed via the user's roles, not here). -->
            <template v-if="rolePerms.length">
              <p class="px-2 pb-1 pt-1.5 text-[11px] font-medium uppercase tracking-wide text-muted">
                From roles
              </p>
              <div
                v-for="perm in rolePerms"
                :key="`role-${slugOf(perm)}`"
                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm text-muted"
                :title="`Inherited from a role: ${slugOf(perm)}`"
              >
                <UIcon name="i-lucide-shield-check" class="size-3.5 shrink-0 text-dimmed" />
                <code class="truncate">{{ slugOf(perm) }}</code>
              </div>
            </template>

            <!-- Direct grants: editable. -->
            <p
              v-if="rolePerms.length"
              class="px-2 pb-1 pt-2.5 text-[11px] font-medium uppercase tracking-wide text-muted"
            >
              Direct
            </p>
            <div
              v-if="rolePerms.length && !assignedPerms.length"
              class="px-2 py-1.5 text-xs text-muted"
            >
              No direct permissions
            </div>
            <button
              v-for="perm in assignedPerms"
              :key="slugOf(perm)"
              type="button"
              class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm transition-colors"
              :class="
                selectedAssigned.has(slugOf(perm))
                  ? 'bg-primary/10 text-primary'
                  : 'text-toned hover:bg-elevated'
              "
              @click="toggleAssigned(slugOf(perm))"
            >
              <span
                class="size-2 shrink-0 rounded-full"
                :class="selectedAssigned.has(slugOf(perm)) ? 'bg-primary' : 'bg-primary/40'"
              />
              <code class="truncate">{{ slugOf(perm) }}</code>
            </button>
          </div>
        </div>
      </div>

      <div class="flex w-full items-center justify-between gap-2">
        <p class="text-xs text-muted">
          Direct grants add to what the user’s roles already provide. Role-inherited permissions are
          shown under “From roles” and are managed via the user’s roles, not here.
        </p>
        <UButton
          icon="i-lucide-check"
          label="Save permissions"
          :loading="save.isLoading.value"
          @click="onSave"
        />
      </div>
    </template>
  </div>
</template>
