import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'

// RBAC via the glueful/aegis extension (/rbac/*). Responses aren't typed in the spec, so the shapes
// are pinned loosely (uuid + the fields the UI shows). Role↔permission assignment has no endpoint —
// roles take name/slug/description/level only — so the permissions list here is read-only.

export interface Role {
  uuid: string
  name: string
  slug: string
  description?: string | null
  level?: number
  status?: string
  [k: string]: unknown
}

export interface Permission {
  uuid: string
  name?: string
  slug?: string
  category?: string
  description?: string | null
  [k: string]: unknown
}

export interface CreateRoleInput {
  name: string
  slug: string
  description?: string
  level?: number
}
export type UpdateRoleInput = Partial<Omit<CreateRoleInput, 'slug'>> & { status?: string }

// successWithMeta / success wrap the payload at `data`, which is sometimes the bare array and
// sometimes `{ data: [...] }`/`{ roles: [...] }`. Normalize to an array.
function asArray<T>(data: unknown, ...keys: string[]): T[] {
  if (Array.isArray(data)) return data as T[]
  const obj = (data ?? {}) as Record<string, unknown>
  for (const k of keys) {
    if (Array.isArray(obj[k])) return obj[k] as T[]
  }
  return []
}

const qkRoles = () => ['rbac', 'roles'] as const
const qkPermissions = () => ['rbac', 'permissions'] as const
const qkUserRoles = (userUuid: string) => ['rbac', 'user-roles', userUuid] as const

// ── Roles ──
export async function fetchRoles(): Promise<Role[]> {
  const json = await authFetch('/rbac/roles')
  return asArray<Role>(json.data, 'data', 'roles')
}

export function useRoles() {
  return useQuery({ key: qkRoles(), query: fetchRoles })
}

export function useRoleMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qkRoles() })

  const create = useMutation({
    mutation: (input: CreateRoleInput) =>
      authFetch('/rbac/roles', { method: 'POST', body: JSON.stringify(input) }),
    onSettled: invalidate,
  })
  const update = useMutation({
    mutation: (vars: { uuid: string; input: UpdateRoleInput }) =>
      authFetch(`/rbac/roles/${encodeURIComponent(vars.uuid)}`, {
        method: 'PUT',
        body: JSON.stringify(vars.input),
      }),
    onSettled: invalidate,
  })
  const remove = useMutation({
    mutation: (uuid: string) =>
      authFetch(`/rbac/roles/${encodeURIComponent(uuid)}`, { method: 'DELETE' }),
    onSettled: invalidate,
  })

  return { create, update, remove }
}

// ── Permissions (read-only) ──
export async function fetchPermissions(): Promise<Permission[]> {
  const json = await authFetch('/rbac/permissions')
  return asArray<Permission>(json.data, 'data', 'permissions')
}

export function usePermissions() {
  return useQuery({ key: qkPermissions(), query: fetchPermissions })
}

// ── A user's roles ──
export async function fetchUserRoles(userUuid: string): Promise<Role[]> {
  const json = await authFetch(`/rbac/users/${encodeURIComponent(userUuid)}/roles`)
  return asArray<Role>(json.data, 'data', 'roles')
}

export function useUserRoles(userUuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qkUserRoles(toValue(userUuid)),
    query: () => fetchUserRoles(toValue(userUuid)),
  })
}

export function useUserRoleMutations(userUuid: string) {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qkUserRoles(userUuid) })

  const assign = useMutation({
    mutation: (roleUuid: string) =>
      authFetch(`/rbac/users/${encodeURIComponent(userUuid)}/roles`, {
        method: 'POST',
        body: JSON.stringify({ role_uuids: [roleUuid] }),
      }),
    onSettled: invalidate,
  })
  const revoke = useMutation({
    mutation: (roleUuid: string) =>
      authFetch(
        `/rbac/users/${encodeURIComponent(userUuid)}/roles/${encodeURIComponent(roleUuid)}`,
        { method: 'DELETE' },
      ),
    onSettled: invalidate,
  })

  return { assign, revoke }
}

// ── A role's permissions (aegis 1.9.0+) ──
const qkRolePermissions = (roleUuid: string) => ['rbac', 'role-permissions', roleUuid] as const

interface RolePermissionGrant {
  permission_uuid?: string
  [k: string]: unknown
}

/** The UUIDs of the permissions currently granted to a role. */
export async function fetchRolePermissionUuids(roleUuid: string): Promise<string[]> {
  const json = await authFetch(`/rbac/roles/${encodeURIComponent(roleUuid)}/permissions`)
  return asArray<RolePermissionGrant>(json.data, 'permissions', 'data')
    .map((g) => String(g.permission_uuid ?? ''))
    .filter((u) => u !== '')
}

export function useRolePermissions(roleUuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qkRolePermissions(toValue(roleUuid)),
    query: () => fetchRolePermissionUuids(toValue(roleUuid)),
  })
}

export function useRolePermissionMutations(roleUuid: string) {
  const cache = useQueryCache()
  // PUT replaces the role's grants with exactly the supplied set (the sync endpoint).
  const replace = useMutation({
    mutation: (permissionUuids: string[]) =>
      authFetch(`/rbac/roles/${encodeURIComponent(roleUuid)}/permissions`, {
        method: 'PUT',
        body: JSON.stringify({ permission_uuids: permissionUuids }),
      }),
    onSettled() {
      cache.invalidateQueries({ key: qkRolePermissions(roleUuid) })
    },
  })

  return { replace }
}
