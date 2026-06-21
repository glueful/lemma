import { describe, it, expect, vi, beforeEach } from 'vitest'

const { getToken } = vi.hoisted(() => ({ getToken: vi.fn(() => 'tok') }))
vi.mock('@/stores/session', () => ({ useSessionStore: () => ({ accessToken: getToken() }) }))

import { uploadBlob } from '@/queries/media'

describe('uploadBlob', () => {
  beforeEach(() => vi.stubGlobal('fetch', vi.fn()))

  it('POSTs multipart to /api/v1/blobs with the bearer and returns data', async () => {
    ;(globalThis.fetch as any).mockResolvedValue(
      new Response(JSON.stringify({ data: { url: '/u/x.png' } }), { status: 201 }),
    )
    const file = new File(['x'], 'x.png', { type: 'image/png' })
    const res = await uploadBlob(file, { visibility: 'public' })

    const [url, init] = (globalThis.fetch as any).mock.calls[0]
    expect(url).toBe('/api/v1/blobs')
    expect(init.method).toBe('POST')
    expect((init.headers as Record<string, string>).authorization).toBe('Bearer tok')
    expect(init.body).toBeInstanceOf(FormData)
    expect(res.url).toBe('/u/x.png')
  })

  it('throws on a non-ok response', async () => {
    ;(globalThis.fetch as any).mockResolvedValue(new Response('{}', { status: 500 }))
    await expect(uploadBlob(new File([''], 'x'))).rejects.toBeTruthy()
  })
})
