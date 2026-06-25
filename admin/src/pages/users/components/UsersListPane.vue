<script setup lang="ts">
import { computed, ref } from 'vue'
import { refDebounced } from '@vueuse/core'
import { useUsers, type UserRow } from '@/queries/users'
import TablePagination from '@/components/TablePagination.vue'
import UserListItem from './UserListItem.vue'

const props = defineProps<{ selectedUuid?: string }>()
const emit = defineEmits<{ select: [user: UserRow]; create: [] }>()

const page = ref(1)
const perPage = ref(25)
const search = ref('')
const debounced = refDebounced(search, 300)
const { data, status } = useUsers(
  page,
  perPage,
  computed(() => debounced.value || undefined),
)
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-col gap-3 lg:w-[340px] lg:shrink-0">
    <div class="flex items-center justify-between gap-2">
      <h2 class="text-lg font-semibold text-highlighted">Users</h2>
      <UButton icon="i-lucide-plus" size="sm" @click="emit('create')">New</UButton>
    </div>

    <UInput v-model="search" icon="i-lucide-search" placeholder="Search users…" class="w-full" />

    <div class="min-h-0 flex-1 overflow-y-auto">
      <div v-if="status === 'pending'" class="flex justify-center py-10">
        <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-muted" />
      </div>
      <UEmpty
        v-else-if="!(data?.users ?? []).length"
        icon="i-lucide-users"
        title="No users"
        description="The list is empty, or it's disabled (set USERS_USER_LIST_ENABLED=true)."
      />
      <div v-else class="flex flex-col gap-0.5">
        <UserListItem
          v-for="u in data?.users ?? []"
          :key="u.uuid"
          :user="u"
          :selected="u.uuid === props.selectedUuid"
          @select="emit('select', u)"
        />
      </div>
    </div>

    <TablePagination
      v-if="(data?.total ?? 0) > 0"
      v-model:page="page"
      v-model:per-page="perPage"
      :total="data?.total ?? 0"
      label="users"
    />
  </div>
</template>
