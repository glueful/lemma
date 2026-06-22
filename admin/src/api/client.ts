import createClient, { type Middleware } from 'openapi-fetch'
import type { paths } from './schema'
import { runtimeConfig } from '@/runtime/config'

// One typed client for the whole app. baseUrl is the admin API base PATH (e.g. /v1/admin); the app
// is served same-origin, so calls resolve relative to the page.
export const client = createClient<paths>({ baseUrl: runtimeConfig.apiBase })

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
