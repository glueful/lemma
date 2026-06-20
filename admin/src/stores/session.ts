import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { PersistOptions } from '@/plugins/pinia-persist-plugin'

export interface SessionUser {
  uuid: string
  email: string
}

// Auth is cookie/credential based and lives OUTSIDE the typed /v1/admin client surface — the
// framework mounts it under the /api/v1/auth prefix (confirmed against docs/openapi.json), a
// DIFFERENT prefix from the admin API (/v1/admin). So these are absolute paths via raw fetch,
// not derived from runtimeConfig.apiBase.
const AUTH = {
  login: '/api/v1/auth/login',
  refresh: '/api/v1/auth/refresh-token',
  logout: '/api/v1/auth/logout',
} as const

// Encrypted-localStorage persistence (maintainer's accepted tradeoff). Only the token + user are
// persisted; everything else is derived. The secret is build-time (VITE_*); client-side
// encryption-at-rest is obfuscation, not secrecy — short-lived tokens + refresh are the real
// defense. The router guard always re-checks isAuthenticated, so restore is best-effort.
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

export const useSessionStore = defineStore(
  'session',
  () => {
    const accessToken = ref<string | null>(null)
    const user = ref<SessionUser | null>(null)
    const isAuthenticated = computed(() => accessToken.value !== null)

    function setSession(token: string, u: SessionUser) {
      accessToken.value = token
      user.value = u
    }
    function clear() {
      accessToken.value = null
      user.value = null
    }

    async function login(email: string, password: string): Promise<void> {
      const res = await fetch(AUTH.login, {
        method: 'POST',
        credentials: 'include',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ email, password }),
      })
      if (!res.ok) throw new Error('login failed')
      const { data } = await res.json()
      setSession(data.token, data.user)
    }

    // Refresh via the httpOnly refresh cookie. Returns true on success (token swapped), false otherwise.
    async function refresh(): Promise<boolean> {
      try {
        const res = await fetch(AUTH.refresh, { method: 'POST', credentials: 'include' })
        if (!res.ok) return false
        const { data } = await res.json()
        accessToken.value = data.token
        if (data.user) user.value = data.user
        return true
      } catch {
        return false
      }
    }

    async function logout(): Promise<void> {
      try {
        await fetch(AUTH.logout, { method: 'POST', credentials: 'include' })
      } finally {
        clear()
      }
    }

    return { accessToken, user, isAuthenticated, setSession, clear, login, refresh, logout }
  },
  sessionStoreOptions,
)
