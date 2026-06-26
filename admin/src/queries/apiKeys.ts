import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// ── API keys (App\Http\Controllers\ApiKeyAdminController, /v1/admin/api-keys) ────────────────────

export type ApiKeyStatus = 'active' | 'expired' | 'revoked'

export interface ApiKey {
  uuid: string
  name: string
  /** Public, non-secret part of the key (env tag + 8 chars) — safe to display. */
  key_prefix: string
  owner_uuid: string
  owner_label: string | null
  scopes: string[]
  allowed_ips: string[]
  status: ApiKeyStatus
  /** True when this key was minted by rotating an earlier one. */
  is_rotated: boolean
  expires_at: string | null
  revoked_at: string | null
  created_at: string | null
}

export interface ApiKeyPage {
  api_keys: ApiKey[]
  total: number
  current_page: number
  per_page: number
}

export interface CreateApiKeyInput {
  name: string
  scopes?: string[]
  allowed_ips?: string[]
  expires_at?: string | null
}

/** Create/rotate return the plaintext key exactly once — it is never retrievable again. */
export interface SecretResult {
  api_key: ApiKey | null
  plain: string
}

export interface RotateResult extends SecretResult {
  old_expires_at: string
}

const apiKeysBase = () => `${runtimeConfig.apiBase}/api-keys`

export async function fetchApiKeys(params: {
  page: number
  perPage: number
  status?: string
  q?: string
}): Promise<ApiKeyPage> {
  const qs = new URLSearchParams({ page: String(params.page), per_page: String(params.perPage) })
  if (params.status) qs.set('status', params.status)
  if (params.q) qs.set('q', params.q)
  const json = await authFetch(`${apiKeysBase()}?${qs.toString()}`)
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    api_keys: Array.isArray(d.api_keys) ? (d.api_keys as ApiKey[]) : [],
    total: Number(d.total ?? 0),
    current_page: Number(d.current_page ?? params.page),
    per_page: Number(d.per_page ?? params.perPage),
  }
}

export function useApiKeyList(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  status: MaybeRefOrGetter<string | undefined>,
  q: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    key: () => ['api-keys', toValue(page), toValue(status) ?? '', toValue(q) ?? ''],
    query: () =>
      fetchApiKeys({
        page: toValue(page),
        perPage: toValue(perPage),
        status: toValue(status) || undefined,
        q: toValue(q) || undefined,
      }),
  })
}

export async function fetchApiKey(uuid: string): Promise<ApiKey> {
  const json = await authFetch(`${apiKeysBase()}/${encodeURIComponent(uuid)}`)
  const d = (json.data ?? json) as Record<string, unknown>
  return (d.api_key ?? d) as ApiKey
}

export function useApiKey(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['api-keys', 'detail', toValue(uuid) ?? ''],
    query: () => fetchApiKey(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export async function createApiKey(input: CreateApiKeyInput): Promise<SecretResult> {
  const json = await authFetch(apiKeysBase(), { method: 'POST', body: JSON.stringify(input) })
  const d = (json.data ?? json) as Record<string, unknown>
  return { api_key: (d.api_key as ApiKey) ?? null, plain: String(d.plain ?? '') }
}

export async function rotateApiKey(uuid: string, graceHours?: number): Promise<RotateResult> {
  const json = await authFetch(`${apiKeysBase()}/${encodeURIComponent(uuid)}/rotate`, {
    method: 'POST',
    body: JSON.stringify(graceHours != null ? { grace_hours: graceHours } : {}),
  })
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    api_key: (d.api_key as ApiKey) ?? null,
    plain: String(d.plain ?? ''),
    old_expires_at: String(d.old_expires_at ?? ''),
  }
}

export async function revokeApiKey(uuid: string): Promise<void> {
  await authFetch(`${apiKeysBase()}/${encodeURIComponent(uuid)}`, { method: 'DELETE' })
}

export function useApiKeyMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['api-keys'] })

  const create = useMutation({ mutation: createApiKey, onSettled: invalidate })
  const rotate = useMutation({
    mutation: (vars: { uuid: string; graceHours?: number }) =>
      rotateApiKey(vars.uuid, vars.graceHours),
    onSettled: invalidate,
  })
  const revoke = useMutation({ mutation: revokeApiKey, onSettled: invalidate })

  return { create, rotate, revoke }
}

const STATUS_META: Record<ApiKeyStatus, { label: string; color: 'success' | 'warning' | 'error' }> =
  {
    active: { label: 'Active', color: 'success' },
    expired: { label: 'Expired', color: 'warning' },
    revoked: { label: 'Revoked', color: 'error' },
  }

export function apiKeyStatusMeta(status: ApiKeyStatus) {
  return STATUS_META[status] ?? STATUS_META.active
}

export function formatDate(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { dateStyle: 'medium' })
}

export function formatDateTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
