import { describe, it, expect, vi, beforeEach } from 'vitest'

// Mutable doubles the guard reads through the mocked modules. Declared via vi.hoisted so they
// exist before the hoisted vi.mock factories run (which reference them).
const { cfg, session } = vi.hoisted(() => ({
  cfg: { installed: false },
  session: { isAuthenticated: false },
}))

vi.mock('@/runtime/config', () => ({ runtimeConfig: cfg }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => session }))

import { installAndAuthGuard } from '@/router/guard'

function to(path: string, meta: Record<string, unknown> = {}) {
  return { path, fullPath: path, meta } as any
}

describe('install + auth guard', () => {
  beforeEach(() => {
    cfg.installed = false
    session.isAuthenticated = false
  })

  it('redirects everything to /setup when not installed', () => {
    expect(installAndAuthGuard(to('/'))).toEqual({ path: '/setup' })
  })

  it('allows /setup when not installed', () => {
    expect(installAndAuthGuard(to('/setup'))).toBe(true)
  })

  it('redirects /setup to /login once installed', () => {
    cfg.installed = true
    expect(installAndAuthGuard(to('/setup'))).toEqual({ path: '/login' })
  })

  it('redirects a protected route to /login when unauthenticated', () => {
    cfg.installed = true
    expect(installAndAuthGuard(to('/content/page', { requiresAuth: true }))).toEqual({
      path: '/login',
      query: { redirect: '/content/page' },
    })
  })

  it('allows a protected route when authenticated', () => {
    cfg.installed = true
    session.isAuthenticated = true
    expect(installAndAuthGuard(to('/content/page', { requiresAuth: true }))).toBe(true)
  })

  it('bounces /login to / when already authenticated', () => {
    cfg.installed = true
    session.isAuthenticated = true
    expect(installAndAuthGuard(to('/login'))).toEqual({ path: '/' })
  })
})
