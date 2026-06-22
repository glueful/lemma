import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { responseError } from '@/api/errors'
import type { PersistOptions } from '@/plugins/pinia-persist-plugin'

export interface SessionUser {
  uuid: string
  email: string
}

// Glueful auth is COOKIELESS: login returns the access + refresh tokens in the JSON body, and the
// refresh endpoint expects the refresh token in the request BODY (there is no httpOnly cookie).
// So we hold BOTH tokens client-side and send the access token as a Bearer header and the refresh
// token in the refresh body. These auth routes live OUTSIDE the typed /v1/admin client surface
// (framework mounts them under /api/v1/auth), so they are raw fetches against same-origin paths.
const AUTH = {
  login: '/api/v1/auth/login',
  refresh: '/api/v1/auth/refresh-token',
  logout: '/api/v1/auth/logout',
} as const

// Encrypted-localStorage persistence (maintainer's accepted tradeoff). The access + refresh tokens
// and user are persisted; everything else is derived. Because the backend is cookieless, the
// refresh token lives here too — client-side encryption-at-rest is obfuscation, not secrecy, so
// short token lifetimes + rotation are the real defense. The router guard always re-checks
// isAuthenticated, so restore is best-effort.
//
// Declared as a named const (not a fresh object literal at the defineStore call site) so the
// `persist` key — contributed by the pinia-persist-plugin module augmentation — passes the
// options type structurally without tripping an excess-property check.
const sessionStoreOptions: { persist: PersistOptions } = {
  persist: {
    enabled: true,
    strategies: [
      {
        key: 'lemma_session',
        storage: localStorage,
        encrypt: { secret: import.meta.env.VITE_ADMIN_PERSIST_SECRET ?? 'lemma-admin-dev' },
        mergeStrategy: 'shallow',
        debounce: 100,
      },
    ],
  },
}

// Pull the token fields out of an auth response, tolerating either the framework's success envelope
// ({ data: { access_token, ... } }, used by login) or a flat DTO body (used by refresh-token).
function readAuthBody(json: unknown): {
  access: string | null
  refresh: string | null
  user: SessionUser | null
} {
  const root = (json ?? {}) as { data?: unknown }
  const body = (root.data ?? json ?? {}) as {
    access_token?: unknown
    refresh_token?: unknown
    user?: unknown
  }
  return {
    access: typeof body.access_token === 'string' ? body.access_token : null,
    refresh: typeof body.refresh_token === 'string' ? body.refresh_token : null,
    user: (body.user ?? null) as SessionUser | null,
  }
}

export const useSessionStore = defineStore(
  'session',
  () => {
    const accessToken = ref<string | null>(null)
    const refreshToken = ref<string | null>(null)
    const user = ref<SessionUser | null>(null)
    const isAuthenticated = computed(() => accessToken.value !== null)

    function setSession(access: string, refresh: string | null, u: SessionUser) {
      accessToken.value = access
      refreshToken.value = refresh
      user.value = u
    }
    function clear() {
      accessToken.value = null
      refreshToken.value = null
      user.value = null
    }

    async function login(email: string, password: string): Promise<void> {
      const res = await fetch(AUTH.login, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ email, password }),
      })
      if (!res.ok) throw await responseError(res, 'Invalid email or password.')
      const { access, refresh, user: u } = readAuthBody(await res.json())
      if (access === null || u === null) throw new Error('Malformed login response.')
      setSession(access, refresh, u)
    }

    // Mint a fresh access token by POSTing the stored refresh token in the body (no cookie). Refresh
    // tokens are one-time/rotating, so the response carries a NEW refresh token we must store for
    // next time. Returns true on success, false otherwise.
    async function refresh(): Promise<boolean> {
      const token = refreshToken.value
      if (token === null || token === '') return false
      try {
        const res = await fetch(AUTH.refresh, {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ refresh_token: token }),
        })
        if (!res.ok) return false
        const { access, refresh: nextRefresh, user: u } = readAuthBody(await res.json())
        if (access === null) return false
        accessToken.value = access
        if (nextRefresh !== null) refreshToken.value = nextRefresh
        if (u !== null) user.value = u
        return true
      } catch {
        return false
      }
    }

    async function logout(): Promise<void> {
      try {
        // The server identifies the session to terminate from the Bearer access token.
        const token = accessToken.value
        await fetch(AUTH.logout, {
          method: 'POST',
          headers: token !== null ? { authorization: `Bearer ${token}` } : {},
        })
      } finally {
        clear()
      }
    }

    return {
      accessToken,
      refreshToken,
      user,
      isAuthenticated,
      setSession,
      clear,
      login,
      refresh,
      logout,
    }
  },
  sessionStoreOptions,
)
