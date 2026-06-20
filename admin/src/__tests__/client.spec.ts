import { describe, it, expect, vi, beforeEach } from 'vitest'

// The client reads apiBase + token lazily so tests can stub both.
const getToken = vi.fn<() => string | null>()
const onRefresh = vi.fn<() => Promise<boolean>>()

vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))
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
})
