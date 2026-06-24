<script setup lang="ts">
import { reactive, ref, useTemplateRef, watch } from 'vue'
import * as z from 'zod'
import type { Form, FormSubmitEvent, TableColumn, TabsItem } from '@nuxt/ui'
import {
  useRolesPage,
  useRoleMutations,
  usePermissionsPage,
  type Role,
  type Permission,
} from '@/queries/rbac'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'
import RolePermissionsSlideover from './components/RolePermissionsSlideover.vue'
import TablePagination from '@/components/TablePagination.vue'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const tab = ref<'roles' | 'permissions'>('roles')
const tabItems: TabsItem[] = [
  { label: 'Roles', icon: 'i-lucide-shield', value: 'roles', slot: 'roles' },
  { label: 'Permissions', icon: 'i-lucide-key-round', value: 'permissions', slot: 'permissions' },
]

// Server-side pagination — the backend owns page/total. (The assignment modals/slideovers fetch the
// full role/permission list themselves via useRoles()/usePermissions(); that's a separate concern.)
const rolePage = ref(1)
const rolePerPage = ref(10)
const permPage = ref(1)
const permPerPage = ref(10)
const { data: rolesData, status: rolesStatus } = useRolesPage(rolePage, rolePerPage)
const { data: permsData, status: permsStatus } = usePermissionsPage(permPage, permPerPage)
const { create, update, remove } = useRoleMutations()

const roleColumns: TableColumn<Role>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'slug', header: 'Slug' },
  { accessorKey: 'description', header: 'Description' },
  { accessorKey: 'level', header: 'Level' },
  { id: 'actions', header: '' },
]
const permColumns: TableColumn<Permission>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'slug', header: 'Slug' },
  { accessorKey: 'category', header: 'Category' },
]

// ── Role create/edit ──
const showRoleForm = ref(false)
const editingRole = ref<Role | null>(null)
const schema = z.object({
  name: z.string().min(1, 'Name is required.'),
  slug: z
    .string()
    .min(1, 'Slug is required.')
    .regex(/^[a-z0-9_-]+$/, 'Lowercase letters, numbers, “-” and “_” only.'),
  description: z.string().optional(),
})
type Schema = z.output<typeof schema>
const roleForm = reactive({ name: '', slug: '', description: '' })
const slugTouched = ref(false)
const roleFormRef = useTemplateRef<Form<Schema>>('roleFormRef')

function slugify(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}
watch(
  () => roleForm.name,
  (name) => {
    // Slug is immutable on edit; only auto-derive when creating and untouched.
    if (editingRole.value === null && !slugTouched.value) roleForm.slug = slugify(name)
  },
)

function openCreate() {
  editingRole.value = null
  slugTouched.value = false
  Object.assign(roleForm, { name: '', slug: '', description: '' })
  showRoleForm.value = true
}
function openEdit(role: Role) {
  editingRole.value = role
  slugTouched.value = true
  Object.assign(roleForm, { name: role.name, slug: role.slug, description: role.description ?? '' })
  showRoleForm.value = true
}

async function onSubmitRole(event: FormSubmitEvent<Schema>) {
  try {
    if (editingRole.value !== null) {
      // slug is immutable; only name/description are editable.
      await update.mutateAsync({
        uuid: editingRole.value.uuid,
        input: { name: event.data.name, description: event.data.description || undefined },
      })
      success('Role updated')
    } else {
      await create.mutateAsync({
        name: event.data.name,
        slug: event.data.slug,
        description: event.data.description || undefined,
      })
      success('Role created')
    }
    showRoleForm.value = false
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) roleFormRef.value?.setErrors(fieldErrors)
    notifyError(err, 'Couldn’t save role')
  }
}

// ── Delete role ──
const pendingDelete = ref<Role | null>(null)
async function confirmDelete() {
  if (pendingDelete.value === null) return
  try {
    await remove.mutateAsync(pendingDelete.value.uuid)
    success('Role deleted', `“${pendingDelete.value.name}” was removed.`)
    pendingDelete.value = null
  } catch (e) {
    notifyError(e, 'Couldn’t delete role')
  }
}

// ── Manage a role's permissions ──
const managingRole = ref<Role | null>(null)
</script>

<template>
  <UDashboardPanel id="roles-permissions">
    <template #header>
      <UDashboardNavbar title="Roles & Permissions">
        <template #right>
          <UButton v-if="tab === 'roles'" icon="i-lucide-plus" @click="openCreate"
            >New role</UButton
          >
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <UTabs v-model="tab" :items="tabItems" variant="link" class="w-full">
        <template #roles>
          <UTable
            :data="rolesData?.data ?? []"
            :columns="roleColumns"
            :loading="rolesStatus === 'pending'"
          >
            <template #name-cell="{ row }">
              <span class="font-medium text-default">{{ row.original.name }}</span>
            </template>
            <template #slug-cell="{ row }">
              <code class="text-xs text-muted">{{ row.original.slug }}</code>
            </template>
            <template #description-cell="{ row }">
              <span class="text-sm text-muted">{{ row.original.description || '—' }}</span>
            </template>
            <template #level-cell="{ row }">
              <span class="text-sm text-muted">{{ row.original.level ?? 0 }}</span>
            </template>
            <template #actions-cell="{ row }">
              <div class="flex justify-end gap-1">
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-key-round"
                  aria-label="Permissions"
                  @click="managingRole = row.original"
                />
                <UButton
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-pencil"
                  aria-label="Edit"
                  @click="openEdit(row.original)"
                />
                <UButton
                  color="error"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-trash-2"
                  aria-label="Delete"
                  @click="pendingDelete = row.original"
                />
              </div>
            </template>
            <template #empty>
              <UEmpty
                icon="i-lucide-shield"
                title="No roles"
                description="Create a role to get started."
              >
                <template #actions>
                  <UButton icon="i-lucide-plus" @click="openCreate">New role</UButton>
                </template>
              </UEmpty>
            </template>
          </UTable>
          <TablePagination
            v-if="(rolesData?.total ?? 0) > 0"
            v-model:page="rolePage"
            v-model:per-page="rolePerPage"
            :total="rolesData?.total ?? 0"
            label="roles"
          />
        </template>

        <template #permissions>
          <UTable
            :data="permsData?.data ?? []"
            :columns="permColumns"
            :loading="permsStatus === 'pending'"
          >
            <template #name-cell="{ row }">
              <span class="font-medium text-default">{{
                row.original.name ?? row.original.slug
              }}</span>
            </template>
            <template #slug-cell="{ row }">
              <code class="text-xs text-muted">{{ row.original.slug }}</code>
            </template>
            <template #category-cell="{ row }">
              <UBadge v-if="row.original.category" color="neutral" variant="subtle" size="sm">
                {{ row.original.category }}
              </UBadge>
              <span v-else class="text-muted">—</span>
            </template>
            <template #empty>
              <UEmpty
                icon="i-lucide-key-round"
                title="No permissions"
                description="No permissions are defined."
              />
            </template>
          </UTable>
          <TablePagination
            v-if="(permsData?.total ?? 0) > 0"
            v-model:page="permPage"
            v-model:per-page="permPerPage"
            :total="permsData?.total ?? 0"
            label="permissions"
          />
        </template>
      </UTabs>
    </template>
  </UDashboardPanel>

  <!-- Role create/edit -->
  <UModal v-model:open="showRoleForm" :title="editingRole ? 'Edit role' : 'New role'">
    <template #body>
      <UForm
        id="role-form"
        :schema="schema"
        :state="roleForm"
        class="space-y-4"
        @submit="onSubmitRole"
      >
        <UFormField label="Name" name="name">
          <UInput v-model="roleForm.name" placeholder="Editor" class="w-full" />
        </UFormField>
        <UFormField
          label="Slug"
          name="slug"
          :hint="editingRole ? 'Immutable' : 'Used in code/checks'"
        >
          <UInput
            v-model="roleForm.slug"
            :disabled="editingRole !== null"
            placeholder="editor"
            class="w-full"
            @update:model-value="slugTouched = true"
          />
        </UFormField>
        <UFormField label="Description" name="description">
          <UTextarea v-model="roleForm.description" :rows="2" class="w-full" />
        </UFormField>
      </UForm>
    </template>
    <template #footer>
      <div class="flex w-full justify-end gap-2">
        <UButton color="neutral" variant="ghost" @click="showRoleForm = false">Cancel</UButton>
        <UButton
          type="submit"
          form="role-form"
          :loading="create.isLoading.value || update.isLoading.value"
        >
          {{ editingRole ? 'Save changes' : 'Create role' }}
        </UButton>
      </div>
    </template>
  </UModal>

  <!-- Delete confirm -->
  <UModal
    :open="pendingDelete !== null"
    title="Delete role"
    @update:open="
      (v: boolean) => {
        if (!v) pendingDelete = null
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        Delete <span class="text-default">“{{ pendingDelete?.name }}”</span>? Users assigned this
        role will lose it.
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

  <RolePermissionsSlideover v-if="managingRole" :role="managingRole" @close="managingRole = null" />
</template>
