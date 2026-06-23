<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoles, useUserRoles, useUserRoleMutations, type Role } from '@/queries/rbac'
import { userDisplayName, type UserRow } from '@/queries/users'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ user: UserRow }>()
const emit = defineEmits<{ close: [] }>()

const open = ref(true)
watch(open, (v) => {
  if (!v) emit('close')
})

const { success, error: notifyError } = useNotify()
const { data: allRoles } = useRoles()
const { data: userRoles, status } = useUserRoles(() => props.user.uuid)
const { assign, revoke } = useUserRoleMutations(props.user.uuid)

// Roles the user doesn't already have, as select options.
const assignableRoles = computed(() => {
  const have = new Set((userRoles.value ?? []).map((r) => r.uuid))
  return (allRoles.value ?? [])
    .filter((r) => !have.has(r.uuid))
    .map((r) => ({ label: r.name, value: r.uuid }))
})
const roleToAdd = ref<string | undefined>()

async function onAdd() {
  if (roleToAdd.value === undefined) return
  try {
    await assign.mutateAsync(roleToAdd.value)
    roleToAdd.value = undefined
    success('Role assigned')
  } catch (e) {
    notifyError(e, 'Couldn’t assign role')
  }
}

async function onRemove(role: Role) {
  try {
    await revoke.mutateAsync(role.uuid)
    success('Role removed')
  } catch (e) {
    notifyError(e, 'Couldn’t remove role')
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="`Roles — ${userDisplayName(user)}`">
    <template #body>
      <div class="space-y-4">
        <div class="flex items-end gap-2">
          <UFormField label="Add role" class="flex-1">
            <USelect
              v-model="roleToAdd"
              :items="assignableRoles"
              placeholder="Select a role"
              class="w-full"
            />
          </UFormField>
          <UButton
            :loading="assign.isLoading.value"
            :disabled="roleToAdd === undefined"
            @click="onAdd"
          >
            Assign
          </UButton>
        </div>

        <div v-if="status === 'pending'" class="space-y-2">
          <USkeleton v-for="n in 2" :key="n" class="h-10" />
        </div>
        <UEmpty
          v-else-if="!userRoles?.length"
          icon="i-lucide-shield"
          title="No roles"
          description="This user has no roles assigned."
        />
        <ul v-else class="divide-y divide-default rounded-lg border border-default">
          <li v-for="r in userRoles" :key="r.uuid" class="flex items-center justify-between p-3">
            <div class="min-w-0">
              <p class="truncate text-sm font-medium text-default">{{ r.name }}</p>
              <code class="text-xs text-muted">{{ r.slug }}</code>
            </div>
            <UButton
              color="error"
              variant="ghost"
              size="xs"
              icon="i-lucide-x"
              aria-label="Remove role"
              :loading="revoke.isLoading.value"
              @click="onRemove(r)"
            />
          </li>
        </ul>
      </div>
    </template>
  </UModal>
</template>
