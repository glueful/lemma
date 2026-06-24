<script setup lang="ts">
import { computed, reactive, ref, useTemplateRef } from 'vue'
import { refDebounced } from '@vueuse/core'
import * as z from 'zod'
import type { DropdownMenuItem, Form, FormSubmitEvent, TableColumn } from '@nuxt/ui'
import { useUsers, useUserAdminMutations, userDisplayName, type UserRow } from '@/queries/users'
import { useRoles } from '@/queries/rbac'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
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
  { accessorKey: 'roles', header: 'Roles' },
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

const { success, error: notifyError } = useNotify()
const { create, update, remove } = useUserAdminMutations()
const statusItems = ['active', 'inactive']

// Roles are owned by aegis; create/update send the chosen slugs as `role_slugs` and the backend
// applies them. The full role list feeds the multi-select; each row's current roles arrive via the
// enricher (UserRow.roles), so the table refreshes automatically once the mutation invalidates it.
const { data: allRoles } = useRoles()
const roleOptions = computed(() =>
  (allRoles.value ?? []).map((r) => ({ label: r.name, value: r.slug })),
)
const createRoles = ref<string[]>([])
const editRoles = ref<string[]>([])

// ── Create user ──
const showCreate = ref(false)
const createSchema = z.object({
  username: z.string().min(1, 'Username is required.'),
  email: z.string().email('Enter a valid email.'),
  password: z.string().min(8, 'At least 8 characters.'),
  first_name: z.string().optional(),
  last_name: z.string().optional(),
})
type CreateSchema = z.output<typeof createSchema>
const createForm = reactive({ username: '', email: '', password: '', first_name: '', last_name: '' })
const createFormRef = useTemplateRef<Form<CreateSchema>>('createFormRef')

function openCreate() {
  Object.assign(createForm, { username: '', email: '', password: '', first_name: '', last_name: '' })
  createRoles.value = []
  showCreate.value = true
}

async function onCreate(event: FormSubmitEvent<CreateSchema>) {
  try {
    await create.mutateAsync({
      username: event.data.username,
      email: event.data.email,
      password: event.data.password,
      first_name: event.data.first_name || undefined,
      last_name: event.data.last_name || undefined,
      role_slugs: createRoles.value,
    })
    success('User created', `“${event.data.username}” can now sign in.`)
    showCreate.value = false
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({ name, message }))
    if (fieldErrors.length > 0) createFormRef.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t create user')
  }
}

// ── Edit user ──
const editingUser = ref<UserRow | null>(null)
const editSchema = z.object({
  username: z.string().min(1, 'Username is required.'),
  email: z.string().email('Enter a valid email.'),
  status: z.string(),
  first_name: z.string().optional(),
  last_name: z.string().optional(),
})
type EditSchema = z.output<typeof editSchema>
const editForm = reactive({
  username: '',
  email: '',
  status: 'active',
  first_name: '',
  last_name: '',
})
const editFormRef = useTemplateRef<Form<EditSchema>>('editFormRef')

function openEdit(user: UserRow) {
  editingUser.value = user
  Object.assign(editForm, {
    username: user.username ?? '',
    email: user.email ?? '',
    status: user.status ?? 'active',
    first_name: user.profile?.first_name ?? '',
    last_name: user.profile?.last_name ?? '',
  })
  editRoles.value = (user.roles ?? []).map((r) => r.slug)
}

async function onEdit(event: FormSubmitEvent<EditSchema>) {
  if (editingUser.value === null) return
  try {
    await update.mutateAsync({
      uuid: editingUser.value.uuid,
      input: {
        username: event.data.username,
        email: event.data.email,
        status: event.data.status,
        first_name: event.data.first_name ?? '',
        last_name: event.data.last_name ?? '',
        role_slugs: editRoles.value,
      },
    })
    success('User updated')
    editingUser.value = null
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({ name, message }))
    if (fieldErrors.length > 0) editFormRef.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t update user')
  }
}

// ── Delete user ──
const pendingDelete = ref<UserRow | null>(null)
async function confirmDelete() {
  if (pendingDelete.value === null) return
  try {
    await remove.mutateAsync(pendingDelete.value.uuid)
    success('User deleted', `“${userDisplayName(pendingDelete.value)}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete user')
  }
}

// Per-row actions, grouped: view (roles/permissions) then manage (edit/delete). Grows cleanly as we
// add actions, instead of widening the row with more inline icon buttons.
function getRowActions(user: UserRow): DropdownMenuItem[][] {
  return [
    [
      { label: 'Edit Roles', icon: 'i-lucide-shield-check', onSelect: () => (selectedUser.value = user) },
      { label: 'Permissions', icon: 'i-lucide-key-round', onSelect: () => (permsUser.value = user) },
    ],
    [
      { label: 'Edit', icon: 'i-lucide-pencil', onSelect: () => openEdit(user) },
      {
        label: 'Delete',
        icon: 'i-lucide-trash-2',
        color: 'error',
        onSelect: () => (pendingDelete.value = user),
      },
    ],
  ]
}
</script>

<template>
  <UDashboardPanel id="users">
    <template #header>
      <UDashboardNavbar title="Users">
        <template #right>
          <div class="flex items-center gap-2">
            <UInput v-model="search" icon="i-lucide-search" placeholder="Search…" class="w-64" />
            <UButton icon="i-lucide-plus" @click="openCreate">New user</UButton>
          </div>
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

        <template #roles-cell="{ row }">
          <div class="flex flex-wrap gap-1">
            <UBadge
              v-for="r in row.original.roles ?? []"
              :key="r.uuid"
              :label="r.name"
              color="neutral"
              variant="subtle"
              size="sm"
            />
            <span v-if="!(row.original.roles ?? []).length" class="text-sm text-muted">—</span>
          </div>
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
          <div class="flex justify-end">
            <UDropdownMenu :items="getRowActions(row.original)" :content="{ align: 'end' }">
              <UButton
                color="neutral"
                variant="ghost"
                size="xs"
                icon="i-lucide-ellipsis-vertical"
                aria-label="User actions"
              />
            </UDropdownMenu>
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

  <!-- Create user -->
  <UModal
    v-model:open="showCreate"
    title="New user"
    description="Creates an active, verified account with an admin-set password. Assign roles afterwards."
  >
    <template #body>
      <UForm
        id="user-create-form"
        ref="createFormRef"
        :schema="createSchema"
        :state="createForm"
        class="space-y-4"
        @submit="onCreate"
      >
        <div class="grid grid-cols-2 gap-3">
          <UFormField label="First name" name="first_name">
            <UInput v-model="createForm.first_name" class="w-full" />
          </UFormField>
          <UFormField label="Last name" name="last_name">
            <UInput v-model="createForm.last_name" class="w-full" />
          </UFormField>
        </div>
        <UFormField label="Username" name="username" required>
          <UInput v-model="createForm.username" placeholder="jdoe" class="w-full" />
        </UFormField>
        <UFormField label="Email" name="email" required>
          <UInput
            v-model="createForm.email"
            type="email"
            placeholder="jdoe@example.com"
            class="w-full"
          />
        </UFormField>
        <UFormField label="Password" name="password" required hint="Min 8 characters">
          <UInput v-model="createForm.password" type="password" class="w-full" />
        </UFormField>
        <UFormField label="Roles" hint="Optional">
          <USelectMenu
            v-model="createRoles"
            :items="roleOptions"
            value-key="value"
            multiple
            placeholder="Select roles"
            class="w-full"
          />
        </UFormField>
      </UForm>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" @click="showCreate = false">Cancel</UButton>
        <UButton type="submit" form="user-create-form" :loading="create.isLoading.value">
          Create user
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- Edit user -->
  <UModal
    :open="editingUser !== null"
    title="Edit user"
    description="Update the account and profile. Password and roles are managed separately."
    @update:open="
      (v: boolean) => {
        if (!v) editingUser = null
      }
    "
  >
    <template #body>
      <UForm
        id="user-edit-form"
        ref="editFormRef"
        :schema="editSchema"
        :state="editForm"
        class="space-y-4"
        @submit="onEdit"
      >
        <div class="grid grid-cols-2 gap-3">
          <UFormField label="First name" name="first_name">
            <UInput v-model="editForm.first_name" class="w-full" />
          </UFormField>
          <UFormField label="Last name" name="last_name">
            <UInput v-model="editForm.last_name" class="w-full" />
          </UFormField>
        </div>
        <UFormField label="Username" name="username" required>
          <UInput v-model="editForm.username" class="w-full" />
        </UFormField>
        <UFormField label="Email" name="email" required>
          <UInput v-model="editForm.email" type="email" class="w-full" />
        </UFormField>
        <UFormField label="Status" name="status">
          <USelect v-model="editForm.status" :items="statusItems" class="w-full" />
        </UFormField>
        <UFormField label="Roles">
          <USelectMenu
            v-model="editRoles"
            :items="roleOptions"
            value-key="value"
            multiple
            placeholder="Select roles"
            class="w-full"
          />
        </UFormField>
      </UForm>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" @click="editingUser = null">Cancel</UButton>
        <UButton type="submit" form="user-edit-form" :loading="update.isLoading.value">
          Save changes
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- Delete confirm -->
  <UModal
    :open="pendingDelete !== null"
    title="Delete user"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete
        <span class="text-default">“{{ pendingDelete ? userDisplayName(pendingDelete) : '' }}”</span>?
        They lose access immediately. The account is soft-deleted, so it can be restored later.
      </p>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="remove.isLoading.value"
          @click="pendingDelete = null"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Delete"
          :loading="remove.isLoading.value"
          @click="confirmDelete"
        />
      </div>
    </template>
  </UModal>
</template>
