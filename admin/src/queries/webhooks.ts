import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// ── Webhooks (framework WebhookController, mounted at /v1/admin/webhooks) ─────────────────────────
//
// These routes delegate to Glueful\Api\Webhooks\Http\Controllers\WebhookController, which wraps the
// subscriptions in a `{ subscriptions, pagination }` (resp. `{ deliveries, pagination }`) envelope —
// slightly different from the app's own `{ items, total, … }` shape, so the fetchers normalise it.

export type WebhookDeliveryStatus = 'pending' | 'delivered' | 'failed' | 'retrying'

/** The subscribable Lemma content events (frozen taxonomy) plus wildcard patterns. */
export const WEBHOOK_EVENTS = [
  'entry.created',
  'entry.updated',
  'entry.published',
  'entry.unpublished',
  'entry.deleted',
  'model.created',
  'model.updated',
  'model.deleted',
  'asset.attached',
  'asset.detached',
] as const

/** Wildcard patterns the backend matches (exact | `*` | `prefix.*`). Offered alongside the events. */
export const WEBHOOK_EVENT_PATTERNS = ['*', 'entry.*', 'model.*', 'asset.*'] as const

export interface WebhookSubscription {
  uuid: string
  url: string
  events: string[]
  is_active: boolean
  metadata: Record<string, unknown> | null
  created_at: string | null
  updated_at: string | null
}

export interface WebhookSubscriptionPage {
  subscriptions: WebhookSubscription[]
  total: number
  current_page: number
  per_page: number
  total_pages: number
}

export interface CreateSubscriptionInput {
  url: string
  events: string[]
}

export interface UpdateSubscriptionInput {
  url?: string
  events?: string[]
  is_active?: boolean
}

export interface SubscriptionStats {
  uuid: string
  period_days: number
  total_deliveries: number
  delivered: number
  failed: number
  pending: number
  success_rate: number
}

export interface WebhookDelivery {
  uuid: string
  event: string
  status: WebhookDeliveryStatus
  attempts: number
  response_code: number | null
  delivered_at: string | null
  next_retry_at: string | null
  created_at: string | null
}

export interface WebhookDeliveryDetail extends WebhookDelivery {
  payload: Record<string, unknown> | null
  response_body: string | null
}

export interface WebhookDeliveryPage {
  deliveries: WebhookDelivery[]
  total: number
  current_page: number
  per_page: number
  total_pages: number
}

export interface TestResult {
  status_code: number | null
  response: string | null
}

const base = () => `${runtimeConfig.apiBase}/webhooks`

function pageMeta(
  pagination: Record<string, unknown> | undefined,
  fallbackPage: number,
  fallbackPer: number,
) {
  const p = pagination ?? {}
  return {
    total: Number(p.total ?? 0),
    current_page: Number(p.current_page ?? fallbackPage),
    per_page: Number(p.per_page ?? fallbackPer),
    total_pages: Number(p.total_pages ?? 1),
  }
}

// ── Subscriptions ────────────────────────────────────────────────────────────────────────────────

export async function fetchSubscriptions(params: {
  page: number
  perPage: number
  activeOnly?: boolean
}): Promise<WebhookSubscriptionPage> {
  const qs = new URLSearchParams({ page: String(params.page), per_page: String(params.perPage) })
  if (params.activeOnly) qs.set('active', 'true')
  const json = await authFetch(`${base()}/subscriptions?${qs.toString()}`)
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    subscriptions: Array.isArray(d.subscriptions) ? (d.subscriptions as WebhookSubscription[]) : [],
    ...pageMeta(d.pagination as Record<string, unknown> | undefined, params.page, params.perPage),
  }
}

export function useSubscriptionList(
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  activeOnly: MaybeRefOrGetter<boolean>,
) {
  return useQuery({
    key: () => ['webhooks', 'subscriptions', toValue(page), toValue(activeOnly) ? 'active' : 'all'],
    query: () =>
      fetchSubscriptions({
        page: toValue(page),
        perPage: toValue(perPage),
        activeOnly: toValue(activeOnly),
      }),
  })
}

export async function fetchSubscription(uuid: string): Promise<WebhookSubscription> {
  const json = await authFetch(`${base()}/subscriptions/${encodeURIComponent(uuid)}`)
  return ((json.data ?? json) as WebhookSubscription) ?? null
}

export function useSubscription(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['webhooks', 'subscriptions', 'detail', toValue(uuid) ?? ''],
    query: () => fetchSubscription(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export async function fetchSubscriptionStats(uuid: string, days = 30): Promise<SubscriptionStats> {
  const json = await authFetch(
    `${base()}/subscriptions/${encodeURIComponent(uuid)}/stats?days=${days}`,
  )
  return (json.data ?? json) as SubscriptionStats
}

export function useSubscriptionStats(uuid: MaybeRefOrGetter<string | undefined>, days = 30) {
  return useQuery({
    key: () => ['webhooks', 'subscriptions', 'stats', toValue(uuid) ?? '', days],
    query: () => fetchSubscriptionStats(toValue(uuid) as string, days),
    enabled: () => !!toValue(uuid),
  })
}

export async function createSubscription(
  input: CreateSubscriptionInput,
): Promise<{ subscription: WebhookSubscription; secret: string }> {
  const json = await authFetch(`${base()}/subscriptions`, {
    method: 'POST',
    body: JSON.stringify(input),
  })
  const d = (json.data ?? json) as WebhookSubscription & { secret?: string }
  return { subscription: d, secret: String(d.secret ?? '') }
}

export async function updateSubscription(
  uuid: string,
  input: UpdateSubscriptionInput,
): Promise<WebhookSubscription> {
  const json = await authFetch(`${base()}/subscriptions/${encodeURIComponent(uuid)}`, {
    method: 'PATCH',
    body: JSON.stringify(input),
  })
  return (json.data ?? json) as WebhookSubscription
}

export async function deleteSubscription(uuid: string): Promise<void> {
  await authFetch(`${base()}/subscriptions/${encodeURIComponent(uuid)}`, { method: 'DELETE' })
}

export async function rotateSubscriptionSecret(uuid: string): Promise<string> {
  const json = await authFetch(
    `${base()}/subscriptions/${encodeURIComponent(uuid)}/rotate-secret`,
    {
      method: 'POST',
    },
  )
  const d = (json.data ?? json) as { secret?: string }
  return String(d.secret ?? '')
}

export async function testSubscription(uuid: string): Promise<TestResult> {
  const json = await authFetch(`${base()}/subscriptions/${encodeURIComponent(uuid)}/test`, {
    method: 'POST',
  })
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    status_code: d.status_code != null ? Number(d.status_code) : null,
    response: d.response != null ? String(d.response) : null,
  }
}

// ── Deliveries ───────────────────────────────────────────────────────────────────────────────────

export async function fetchDeliveries(params: {
  subscription?: string
  status?: string
  page: number
  perPage: number
}): Promise<WebhookDeliveryPage> {
  const qs = new URLSearchParams({ page: String(params.page), per_page: String(params.perPage) })
  if (params.subscription) qs.set('subscription', params.subscription)
  if (params.status) qs.set('status', params.status)
  const json = await authFetch(`${base()}/deliveries?${qs.toString()}`)
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    deliveries: Array.isArray(d.deliveries) ? (d.deliveries as WebhookDelivery[]) : [],
    ...pageMeta(d.pagination as Record<string, unknown> | undefined, params.page, params.perPage),
  }
}

export function useDeliveryList(
  subscription: MaybeRefOrGetter<string | undefined>,
  status: MaybeRefOrGetter<string | undefined>,
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
) {
  return useQuery({
    key: () => [
      'webhooks',
      'deliveries',
      toValue(subscription) ?? '',
      toValue(status) ?? '',
      toValue(page),
    ],
    query: () =>
      fetchDeliveries({
        subscription: toValue(subscription) || undefined,
        status: toValue(status) || undefined,
        page: toValue(page),
        perPage: toValue(perPage),
      }),
    enabled: () => !!toValue(subscription),
  })
}

export async function fetchDelivery(uuid: string): Promise<WebhookDeliveryDetail> {
  const json = await authFetch(`${base()}/deliveries/${encodeURIComponent(uuid)}`)
  return (json.data ?? json) as WebhookDeliveryDetail
}

export function useDelivery(uuid: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['webhooks', 'deliveries', 'detail', toValue(uuid) ?? ''],
    query: () => fetchDelivery(toValue(uuid) as string),
    enabled: () => !!toValue(uuid),
  })
}

export async function retryDelivery(uuid: string): Promise<void> {
  await authFetch(`${base()}/deliveries/${encodeURIComponent(uuid)}/retry`, { method: 'POST' })
}

export function useWebhookMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['webhooks'] })

  const create = useMutation({ mutation: createSubscription, onSettled: invalidate })
  const update = useMutation({
    mutation: (vars: { uuid: string; input: UpdateSubscriptionInput }) =>
      updateSubscription(vars.uuid, vars.input),
    onSettled: invalidate,
  })
  const remove = useMutation({ mutation: deleteSubscription, onSettled: invalidate })
  const rotateSecret = useMutation({ mutation: rotateSubscriptionSecret })
  const test = useMutation({ mutation: testSubscription })
  const retry = useMutation({ mutation: retryDelivery, onSettled: invalidate })

  return { create, update, remove, rotateSecret, test, retry }
}

const DELIVERY_STATUS_META: Record<
  WebhookDeliveryStatus,
  { label: string; color: 'success' | 'warning' | 'error' | 'neutral' }
> = {
  delivered: { label: 'Delivered', color: 'success' },
  pending: { label: 'Pending', color: 'neutral' },
  retrying: { label: 'Retrying', color: 'warning' },
  failed: { label: 'Failed', color: 'error' },
}

export function deliveryStatusMeta(status: WebhookDeliveryStatus) {
  return DELIVERY_STATUS_META[status] ?? DELIVERY_STATUS_META.pending
}

export function formatDateTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(String(v).replace(' ', 'T'))
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
