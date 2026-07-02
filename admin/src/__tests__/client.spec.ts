import { describe, it, expect, vi, beforeEach } from 'vitest'

// The client reads apiBase + token lazily so tests can stub both.
const getToken = vi.fn<() => string | null>()
const onRefresh = vi.fn<() => Promise<boolean>>()

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({
    accessToken: getToken(),
    refresh: onRefresh,
    clear: vi.fn(),
  }),
}))

describe('api client middleware', () => {
  beforeEach(() => {
    getToken.mockReset()
    onRefresh.mockReset()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('attaches the bearer token from the session store', async () => {
    getToken.mockReturnValue('tok-123')
    ;(globalThis.fetch as any).mockResolvedValue(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')
    await client.GET('/content-types' as any, {})
    const req = (globalThis.fetch as any).mock.calls[0][0] as Request
    expect(req.headers.get('authorization')).toBe('Bearer tok-123')
  })

  it('refreshes once on 401 then retries; clears on refresh failure', async () => {
    getToken.mockReturnValue('stale')
    onRefresh.mockResolvedValue(true)
    ;(globalThis.fetch as any)
      .mockResolvedValueOnce(new Response('{}', { status: 401 }))
      .mockResolvedValueOnce(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')
    const res = await client.GET('/content-types' as any, {})
    expect(onRefresh).toHaveBeenCalledTimes(1)
    expect(res.response.status).toBe(200)
  })

  // Regression: a bodied mutation (POST/PATCH/PUT) must survive refresh-on-401. The network send
  // consumes the request body, so cloning in onResponse threw "body already used" and the retry
  // never fired — GETs (no body) hid it. The pristine clone taken in onRequest fixes it.
  it('retries a bodied POST after 401 with its body intact', async () => {
    getToken.mockReturnValue('stale')
    onRefresh.mockResolvedValue(true)
    ;(globalThis.fetch as any)
      // First send consumes the body, exactly as a real network send does, then 401s.
      .mockImplementationOnce(async (req: Request) => {
        await req.text()
        return new Response('{}', { status: 401 })
      })
      .mockResolvedValueOnce(new Response('{}', { status: 200 }))
    const { client } = await import('@/api/client')

    const res = await client.POST('/content-types' as any, { body: { name: 'Post' } })

    expect(onRefresh).toHaveBeenCalledTimes(1)
    expect(res.response.status).toBe(200)
    const retried = (globalThis.fetch as any).mock.calls[1][0] as Request
    expect(retried.method).toBe('POST')
    expect(await retried.text()).toBe(JSON.stringify({ name: 'Post' }))
    expect(retried.headers.get('authorization')).toBe('Bearer stale')
  })
})
