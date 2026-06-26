import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from './schema'
import type { paths as corePaths } from './core-schema'
import { runtimeConfig } from '@/runtime/config'

// Admin client: the bulk of the app. baseUrl = apiBase (/v1/admin), schema paths are relative
// (client.GET('/content-types')). Full auth: Bearer + refresh-on-401.
export const client = createClient<paths>({ baseUrl: runtimeConfig.apiBase })

// Core client: everything OUTSIDE /v1/admin (auth, account, 2FA, me, users, blobs, …). baseUrl is
// empty (same-origin) and schema paths are FULL (core.POST('/v1/auth/login')). It attaches the
// Bearer when one exists — so protected core endpoints like /v1/me work — but it does NOT
// refresh-on-401: it also carries the PRE-token auth endpoints (login, forgot-password), where a
// refresh loop is nonsensical. A 401 here surfaces to the caller / router guard instead.
export const core = createClient<corePaths>({ baseUrl: '' })

// Attach the bearer from the session store on every request. Imported lazily inside the
// hook to avoid a Pinia<->client module cycle at load time.
const authMiddleware: Middleware = {
  async onRequest({ request }) {
    const { useSessionStore } = await import('@/stores/session')
    const token = useSessionStore().accessToken
    if (token) request.headers.set('authorization', `Bearer ${token}`)
    return request
  },
}

// Refresh-on-401: on a 401, attempt a single refresh; on success retry the original request
// once; on failure clear the session (the router guard then routes to /login).
let refreshing: Promise<boolean> | null = null
const refreshMiddleware: Middleware = {
  async onResponse({ request, response }) {
    if (response.status !== 401) return response
    const { useSessionStore } = await import('@/stores/session')
    const session = useSessionStore()
    refreshing ??= session.refresh().finally(() => {
      refreshing = null
    })
    const ok = await refreshing
    if (!ok) {
      session.clear()
      return response
    }
    const retry = request.clone()
    retry.headers.set('authorization', `Bearer ${session.accessToken ?? ''}`)
    return fetch(retry)
  },
}

client.use(authMiddleware)
client.use(refreshMiddleware)

// Core gets the Bearer attach but NOT refresh-on-401 (see note above).
core.use(authMiddleware)
