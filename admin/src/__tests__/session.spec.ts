import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

describe('session store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.stubGlobal('fetch', vi.fn())
  })

  it('starts unauthenticated; setSession authenticates', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    expect(s.isAuthenticated).toBe(false)
    s.setSession('tok', { uuid: 'u1', email: 'a@b.c' })
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('tok')
  })

  it('login posts credentials and stores the returned token', async () => {
    ;(globalThis.fetch as any).mockResolvedValue(
      new Response(
        JSON.stringify({ data: { token: 'jwt', user: { uuid: 'u1', email: 'a@b.c' } } }),
        {
          status: 200,
        },
      ),
    )
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    await s.login('a@b.c', 'pw')
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('jwt')
  })

  it('clear() wipes the session', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    s.setSession('tok', { uuid: 'u1', email: 'a@b.c' })
    s.clear()
    expect(s.isAuthenticated).toBe(false)
    expect(s.accessToken).toBeNull()
  })
})
