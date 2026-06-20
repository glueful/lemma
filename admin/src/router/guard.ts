import type { RouteLocationNormalized, RouteLocationRaw } from 'vue-router'
import { runtimeConfig } from '@/runtime/config'
import { useSessionStore } from '@/stores/session'

declare module 'vue-router' {
  interface RouteMeta {
    // Protected pages opt in via definePage({ meta: { requiresAuth: true } }).
    requiresAuth?: boolean
  }
}

/**
 * Global navigation guard, extracted as a pure function so it can be unit-tested without a router.
 *
 * Order matters: the install gate runs first (a fresh instance has no admin to authenticate
 * against, so everything funnels to /setup), then the auth gate.
 *
 * Returns `true` to allow navigation, or a redirect target.
 */
export function installAndAuthGuard(to: RouteLocationNormalized): true | RouteLocationRaw {
  // (1) Install gate. Until setup has run, force everything to /setup; once installed, /setup is inert.
  if (!runtimeConfig.installed && to.path !== '/setup') return { path: '/setup' }
  if (runtimeConfig.installed && to.path === '/setup') return { path: '/login' }

  // (2) Auth gate. Protected pages opt in via meta.requiresAuth; /login bounces home when signed in.
  const session = useSessionStore()
  if (to.meta.requiresAuth && !session.isAuthenticated) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }
  if (to.path === '/login' && session.isAuthenticated) return { path: '/' }
  return true
}
