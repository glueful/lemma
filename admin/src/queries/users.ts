import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'

// Users are read-only via the API: GET /v1/users (paginated list) + GET /v1/users/{uuid}. The list
// is off by default (USERS_USER_LIST_ENABLED) and needs the `users.read` permission. Each row is a
// merged account + nested public `profile`; the exact columns are config-driven, so we pin loosely.
export interface UserProfile {
  first_name?: string
  last_name?: string
  [k: string]: unknown
}

export interface UserRow {
  uuid: string
  username?: string
  email?: string
  status?: string
  created_at?: string
  profile?: UserProfile | null
  [k: string]: unknown
}

export interface UsersPage {
  users: UserRow[]
  total: number
  current_page: number
  per_page: number
}

export async function fetchUsers(params: {
  page: number
  perPage: number
  search?: string
}): Promise<UsersPage> {
  const q = new URLSearchParams({ page: String(params.page), per_page: String(params.perPage) })
  if (params.search) q.set('search', params.search)
  const json = await authFetch(`/v1/users?${q.toString()}`)
  const d = (json.data ?? {}) as {
    data?: UserRow[]
    total?: number
    current_page?: number
    per_page?: number
  }
  return {
    users: d.data ?? [],
    total: d.total ?? 0,
    current_page: d.current_page ?? params.page,
    per_page: d.per_page ?? params.perPage,
  }
}

export function useUsers(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  search: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    key: () => ['users', toValue(page), toValue(search) ?? ''],
    query: () =>
      fetchUsers({ page: toValue(page), perPage: toValue(perPage), search: toValue(search) }),
  })
}

/** Display name for a user row: profile name → username → email → short uuid. */
export function userDisplayName(u: UserRow): string {
  const full = [u.profile?.first_name, u.profile?.last_name].filter(Boolean).join(' ').trim()
  return full || u.username || u.email || u.uuid.slice(0, 8)
}
