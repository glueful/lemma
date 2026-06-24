<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import { useUsers, userDisplayName, type UserRow } from '@/queries/users'
import UserRolesModal from './components/UserRolesModal.vue'
import UserPermissionsSlideover from './components/UserPermissionsSlideover.vue'
import TablePagination from '@/components/TablePagination.vue'

definePage({ meta: { requiresAuth: true } })

const page = ref(1)
const perPage = ref(25)
const search = ref('')
const debouncedSearch = refDebounced(search, 300)
const { data, status } = useUsers(
  page,
  perPage,
  computed(() => debouncedSearch.value || undefined),
)

const columns: TableColumn<UserRow>[] = [
  { accessorKey: 'username', header: 'User' },
  { accessorKey: 'email', header: 'Email' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'email_verified_at', header: 'Verified' },
  { accessorKey: 'two_factor_enabled', header: '2FA' },
  { accessorKey: 'created_at', header: 'Created' },
  { id: 'actions', header: '' },
]

/** Short, locale-aware date (or em dash when absent/unparsable). */
function fmtDate(value?: string | null): string {
  if (!value) return '—'
  const d = new Date(value.replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

const selectedUser = ref<UserRow | null>(null)
const permsUser = ref<UserRow | null>(null)
</script>

<template>
  <UDashboardPanel id="users">
    <template #header>
      <UDashboardNavbar title="Users">
        <template #right>
          <UInput v-model="search" icon="i-lucide-search" placeholder="Search…" class="w-64" />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTable :data="data?.users ?? []" :columns="columns" :loading="status === 'pending'">
        <template #username-cell="{ row }">
          <p class="truncate font-medium text-default">{{ userDisplayName(row.original) }}</p>
        </template>

        <template #email-cell="{ row }">
          <span class="text-sm text-muted">{{ row.original.email ?? '—' }}</span>
        </template>

        <template #status-cell="{ row }">
          <UBadge
            :color="row.original.status === 'active' ? 'success' : 'neutral'"
            variant="subtle"
          >
            {{ row.original.status ?? 'active' }}
          </UBadge>
        </template>

        <template #email_verified_at-cell="{ row }">
          <span
            v-if="row.original.email_verified_at"
            class="inline-flex items-center gap-1 text-sm text-muted"
          >
            <UIcon name="i-lucide-circle-check" class="size-4 text-success" />
            {{ fmtDate(row.original.email_verified_at) }}
          </span>
          <span v-else class="text-sm text-muted">—</span>
        </template>

        <template #two_factor_enabled-cell="{ row }">
          <UBadge
            :color="row.original.two_factor_enabled ? 'success' : 'neutral'"
            variant="subtle"
            size="sm"
          >
            {{ row.original.two_factor_enabled ? 'On' : 'Off' }}
          </UBadge>
        </template>

        <template #created_at-cell="{ row }">
          <span class="text-sm text-muted">{{ fmtDate(row.original.created_at) }}</span>
        </template>

        <template #actions-cell="{ row }">
          <div class="flex justify-end gap-1">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-shield-check"
              @click="selectedUser = row.original"
            >
              Roles
            </UButton>
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-key-round"
              @click="permsUser = row.original"
            >
              Permissions
            </UButton>
          </div>
        </template>

        <template #empty>
          <UEmpty
            icon="i-lucide-users"
            title="No users"
            description="The list is empty, or it's disabled (set USERS_USER_LIST_ENABLED=true)."
          />
        </template>
      </UTable>

      <TablePagination
        v-if="(data?.total ?? 0) > 0"
        v-model:page="page"
        v-model:per-page="perPage"
        :total="data?.total ?? 0"
        label="users"
      />
    </template>
  </UDashboardPanel>

  <UserRolesModal v-if="selectedUser" :user="selectedUser" @close="selectedUser = null" />
  <UserPermissionsSlideover v-if="permsUser" :user="permsUser" @close="permsUser = null" />
</template>
