<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import { useUsers, userDisplayName, type UserRow } from '@/queries/users'
import UserRolesModal from './components/UserRolesModal.vue'

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
  { id: 'actions', header: '' },
]

const selectedUser = ref<UserRow | null>(null)
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
          <div class="min-w-0">
            <p class="truncate font-medium text-default">{{ userDisplayName(row.original) }}</p>
            <code class="text-xs text-muted">{{ row.original.uuid.slice(0, 8) }}</code>
          </div>
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

        <template #actions-cell="{ row }">
          <div class="flex justify-end">
            <UButton
              color="neutral"
              variant="ghost"
              size="xs"
              icon="i-lucide-shield-check"
              @click="selectedUser = row.original"
            >
              Roles
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

      <div v-if="(data?.total ?? 0) > perPage" class="flex justify-end">
        <UPagination v-model:page="page" :total="data?.total ?? 0" :items-per-page="perPage" />
      </div>
    </template>
  </UDashboardPanel>

  <UserRolesModal v-if="selectedUser" :user="selectedUser" @close="selectedUser = null" />
</template>
