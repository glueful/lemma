import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'

// RBAC via the glueful/aegis extension (/rbac/*). Responses aren't typed in the spec, so the shapes
// are pinned loosely (uuid + the fields the UI shows). The /rbac/roles and /rbac/permissions lists
// are paginated server-side (default 25/page); we request a large page so the assignment modals and
// the client-paginated tables both see the full set. Role↔permission assignment uses the aegis
// 1.9.0 endpoints (see useRolePermissions / useRolePermissionMutations below).
const LIST_PER_PAGE = 200

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
  const json = await authFetch(`/rbac/roles?per_page=${LIST_PER_PAGE}`)
  return asArray<Role>(json.data, 'data', 'roles')
}

export function useRoles() {
  return useQuery({ key: qkRoles(), query: fetchRoles })
}

// Server-paginated list for the roles table — the backend owns page/total (no client slicing).
// Key is prefixed with qkRoles() so role mutations' invalidate({ key: ['rbac','roles'] }) (a prefix
// match) refreshes it too.
export interface RbacPage<T> {
  data: T[]
  total: number
  current_page: number
  per_page: number
}

async function fetchPage<T>(
  path: string,
  keys: string[],
  page: number,
  perPage: number,
): Promise<RbacPage<T>> {
  const q = new URLSearchParams({ page: String(page), per_page: String(perPage) })
  const json = await authFetch(`${path}?${q.toString()}`)
  return {
    data: asArray<T>(json.data, ...keys),
    total: Number(json.total ?? 0),
    current_page: Number(json.current_page ?? page),
    per_page: Number(json.per_page ?? perPage),
  }
}

export function useRolesPage(page: MaybeRefOrGetter<number>, perPage: MaybeRefOrGetter<number>) {
  return useQuery({
    key: () => ['rbac', 'roles', 'page', toValue(page), toValue(perPage)],
    query: () => fetchPage<Role>('/rbac/roles', ['data', 'roles'], toValue(page), toValue(perPage)),
  })
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
  const json = await authFetch(`/rbac/permissions?per_page=${LIST_PER_PAGE}`)
  return asArray<Permission>(json.data, 'data', 'permissions')
}

export function usePermissions() {
  return useQuery({ key: qkPermissions(), query: fetchPermissions })
}

// Server-paginated list for the permissions table.
export function usePermissionsPage(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
) {
  return useQuery({
    key: () => ['rbac', 'permissions', 'page', toValue(page), toValue(perPage)],
    query: () =>
      fetchPage<Permission>(
        '/rbac/permissions',
        ['data', 'permissions'],
        toValue(page),
        toValue(perPage),
      ),
  })
}

// ── A user's roles ──
// Each item is `{ role: {...}, assignment: {...} }` (the grant + the role it points at), so unwrap
// the nested `role`; fall back to the item itself if a flat shape is ever returned.
export async function fetchUserRoles(userUuid: string): Promise<Role[]> {
  const json = await authFetch(`/rbac/users/${encodeURIComponent(userUuid)}/roles`)
  return asArray<{ role?: Role } & Partial<Role>>(json.data, 'data', 'roles')
    .map((item) => (item.role ?? item) as Role)
    .filter((r) => typeof r?.uuid === 'string' && r.uuid !== '')
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

// ── A user's DIRECT permissions (aegis; read needs users.view, change needs users.edit) ──
// These are permissions granted straight to the user, independent of their roles.
const qkUserPermissions = (userUuid: string) => ['rbac', 'user-permissions', userUuid] as const

interface UserDirectPermission {
  permission?: { slug?: string; uuid?: string; [k: string]: unknown }
  [k: string]: unknown
}

/** The slugs of the permissions granted DIRECTLY to a user (role-derived perms are excluded). */
export async function fetchUserPermissionSlugs(userUuid: string): Promise<string[]> {
  const json = await authFetch(`/rbac/users/${encodeURIComponent(userUuid)}/permissions`)
  return asArray<UserDirectPermission>(json.data, 'data', 'permissions')
    .map((g) => String(g.permission?.slug ?? ''))
    .filter((s) => s !== '')
}

export function useUserPermissions(userUuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qkUserPermissions(toValue(userUuid)),
    query: () => fetchUserPermissionSlugs(toValue(userUuid)),
  })
}

// ── A user's ROLE-derived permission slugs ──
// The permissions a user already holds through their assigned roles (NOT direct grants). Computed by
// fanning out over the user's roles, collecting each role's permission UUIDs, and mapping those to
// slugs via the full permissions list. Used to hide already-inherited permissions from the
// direct-grant picker so admins only grant what a role doesn't already provide.
const qkUserRolePermissions = (userUuid: string) =>
  ['rbac', 'user-role-permissions', userUuid] as const

export async function fetchUserRolePermissionSlugs(userUuid: string): Promise<string[]> {
  const [roles, allPerms] = await Promise.all([fetchUserRoles(userUuid), fetchPermissions()])
  const uuidToSlug = new Map(allPerms.map((p) => [p.uuid, p.slug ?? ''] as const))
  const permUuidLists = await Promise.all(roles.map((r) => fetchRolePermissionUuids(r.uuid)))
  const slugs = new Set<string>()
  for (const list of permUuidLists) {
    for (const uuid of list) {
      const slug = uuidToSlug.get(uuid)
      if (slug) slugs.add(slug)
    }
  }
  return [...slugs]
}

export function useUserRolePermissions(userUuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qkUserRolePermissions(toValue(userUuid)),
    query: () => fetchUserRolePermissionSlugs(toValue(userUuid)),
  })
}

export function useUserPermissionMutations(userUuid: string) {
  const cache = useQueryCache()
  // There is no single "sync" endpoint for direct user permissions, so apply the diff: batch-assign
  // the added slugs, batch-revoke the removed ones. Both key off the permission SLUG.
  const save = useMutation({
    mutation: async (vars: { add: string[]; remove: string[] }) => {
      if (vars.add.length > 0) {
        await authFetch('/rbac/permissions/batch-assign', {
          method: 'POST',
          body: JSON.stringify({
            user_uuid: userUuid,
            permissions: vars.add.map((slug) => ({ permission: slug, resource: '*' })),
          }),
        })
      }
      if (vars.remove.length > 0) {
        await authFetch('/rbac/permissions/batch-revoke', {
          method: 'POST',
          body: JSON.stringify({ user_uuid: userUuid, permission_slugs: vars.remove }),
        })
      }
    },
    onSettled() {
      cache.invalidateQueries({ key: qkUserPermissions(userUuid) })
    },
  })

  return { save }
}
