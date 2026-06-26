import { describe, it, expect, vi, beforeEach } from 'vitest'

// uploadBlob now goes through the typed `core` openapi-fetch client (path from the spec, bearer via
// middleware), so mock that rather than global fetch.
const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/api/client', () => ({ core: { POST: post } }))

import { uploadBlob } from '@/queries/media'

describe('uploadBlob', () => {
  beforeEach(() => post.mockReset())

  it('POSTs the file to /v1/blobs via the core client and returns the data', async () => {
    post.mockResolvedValue({
      data: { data: { url: '/u/x.png' } },
      error: undefined,
      response: new Response(),
    })

    const file = new File(['x'], 'x.png', { type: 'image/png' })
    const res = await uploadBlob(file, { visibility: 'public' })

    const [path, opts] = post.mock.calls[0]
    expect(path).toBe('/v1/blobs')

    // The bodySerializer turns the payload into multipart FormData (file + options).
    const form = opts.bodySerializer(opts.body)
    expect(form).toBeInstanceOf(FormData)
    expect(form.get('file')).toBe(file)
    expect(form.get('visibility')).toBe('public')

    expect(res.url).toBe('/u/x.png')
  })

  it('throws on an error response', async () => {
    post.mockResolvedValue({
      data: undefined,
      error: { message: 'boom' },
      response: new Response('{}', { status: 500 }),
    })

    await expect(uploadBlob(new File([''], 'x'))).rejects.toBeTruthy()
  })
})
