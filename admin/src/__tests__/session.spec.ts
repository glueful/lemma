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
    s.setSession('tok', 'rtok', { uuid: 'u1', email: 'a@b.c' })
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('tok')
    expect(s.refreshToken).toBe('rtok')
  })

  it('login stores the access + refresh tokens from the response envelope', async () => {
    ;(globalThis.fetch as any).mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            access_token: 'jwt',
            refresh_token: 'rjwt',
            user: { uuid: 'u1', email: 'a@b.c' },
          },
        }),
        { status: 200 },
      ),
    )
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    await s.login('a@b.c', 'pw')
    expect(s.isAuthenticated).toBe(true)
    expect(s.accessToken).toBe('jwt')
    expect(s.refreshToken).toBe('rjwt')
  })

  it('refresh posts the stored refresh token and rotates it', async () => {
    const fetchMock = globalThis.fetch as any
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify({ access_token: 'jwt2', refresh_token: 'rjwt2' }), {
        status: 200,
      }),
    )
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    s.setSession('jwt', 'rjwt', { uuid: 'u1', email: 'a@b.c' })

    const ok = await s.refresh()

    expect(ok).toBe(true)
    expect(s.accessToken).toBe('jwt2')
    expect(s.refreshToken).toBe('rjwt2')
    // The refresh token was sent in the body, not via a cookie.
    const body = JSON.parse(fetchMock.mock.calls.at(-1)[1].body)
    expect(body).toEqual({ refresh_token: 'rjwt' })
  })

  it('refresh returns false when there is no stored refresh token', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    expect(await s.refresh()).toBe(false)
  })

  it('clear() wipes the session', async () => {
    const { useSessionStore } = await import('@/stores/session')
    const s = useSessionStore()
    s.setSession('tok', 'rtok', { uuid: 'u1', email: 'a@b.c' })
    s.clear()
    expect(s.isAuthenticated).toBe(false)
    expect(s.accessToken).toBeNull()
    expect(s.refreshToken).toBeNull()
  })
})
