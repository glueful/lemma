import { describe, it, expect, vi, beforeEach } from 'vitest'

const { GET } = vi.hoisted(() => ({ GET: vi.fn() }))
vi.mock('@/api/client', () => ({ client: { GET } }))

import { fetchContentTypes } from '@/queries/contentTypes'

describe('fetchContentTypes', () => {
  beforeEach(() => GET.mockReset())

  it('returns the content_types array from the admin endpoint', async () => {
    GET.mockResolvedValue({
      data: { data: { content_types: [{ slug: 'page', name: 'Pages' }] } },
      error: undefined,
    })
    const result = await fetchContentTypes()
    expect(GET).toHaveBeenCalledWith('/content-types')
    expect(result).toEqual([{ slug: 'page', name: 'Pages' }])
  })

  it('throws on error', async () => {
    GET.mockResolvedValue({ data: undefined, error: { message: 'boom' } })
    await expect(fetchContentTypes()).rejects.toBeTruthy()
  })

  it('defaults to [] when content_types is missing', async () => {
    GET.mockResolvedValue({ data: { data: {} }, error: undefined })
    expect(await fetchContentTypes()).toEqual([])
  })
})
