<script setup lang="ts">
import { ref, watch } from 'vue'
import {
  usePermissions,
  useRolePermissions,
  useRolePermissionMutations,
  type Role,
} from '@/queries/rbac'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ role: Role }>()
const emit = defineEmits<{ close: [] }>()

const open = ref(true)
watch(open, (v) => {
  if (!v) emit('close')
})

const { success, error: notifyError } = useNotify()
const { data: allPerms, status } = usePermissions()
const { data: current } = useRolePermissions(() => props.role.uuid)
const { replace } = useRolePermissionMutations(props.role.uuid)

// Local selection of permission UUIDs, seeded from the role's current grants. Saving PUTs the whole
// set (the sync endpoint), so the role ends up with exactly what's checked.
const selected = ref<Set<string>>(new Set())
watch(
  current,
  (c) => {
    if (c) selected.value = new Set(c)
  },
  { immediate: true },
)

function toggle(uuid: string, checked: boolean) {
  const next = new Set(selected.value)
  if (checked) {
    next.add(uuid)
  } else {
    next.delete(uuid)
  }
  selected.value = next
}

async function onSave() {
  try {
    await replace.mutateAsync([...selected.value])
    success(
      'Permissions updated',
      `“${props.role.name}” now grants ${selected.value.size} permission(s).`,
    )
    open.value = false
  } catch (e) {
    notifyError(e, 'Couldn’t update permissions')
  }
}
</script>

<template>
  <UModal v-model:open="open" :title="`Permissions — ${role.name}`" :ui="{ content: 'max-w-xl' }">
    <template #body>
      <div v-if="status === 'pending'" class="space-y-2">
        <USkeleton v-for="n in 5" :key="n" class="h-9" />
      </div>
      <UEmpty
        v-else-if="!allPerms?.length"
        icon="i-lucide-key"
        title="No permissions"
        description="There are no permissions defined to assign."
      />
      <div v-else class="max-h-96 space-y-1 overflow-y-auto">
        <label
          v-for="p in allPerms"
          :key="p.uuid"
          class="flex items-start gap-2 rounded-md p-2 hover:bg-elevated/50"
        >
          <UCheckbox
            :model-value="selected.has(p.uuid)"
            @update:model-value="toggle(p.uuid, Boolean($event))"
          />
          <div class="min-w-0">
            <p class="text-sm text-default">{{ p.name ?? p.slug }}</p>
            <code class="text-xs text-muted">{{ p.slug }}</code>
          </div>
        </label>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between gap-2">
        <span class="text-xs text-muted">{{ selected.size }} selected</span>
        <div class="flex gap-2">
          <UButton color="neutral" variant="ghost" @click="open = false">Cancel</UButton>
          <UButton :loading="replace.isLoading.value" @click="onSave">Save</UButton>
        </div>
      </div>
    </template>
  </UModal>
</template>
