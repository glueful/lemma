import { describe, it, expect, vi, beforeEach } from 'vitest'

const { GET } = vi.hoisted(() => ({ GET: vi.fn() }))
vi.mock('@/api/client', () => ({ client: { GET } }))

import { fetchEntries } from '@/queries/entries'

describe('fetchEntries', () => {
  beforeEach(() => GET.mockReset())

  it('passes the query params and returns the page', async () => {
    GET.mockResolvedValue({
      data: {
        data: {
          entries: [
            {
              uuid: 'e1',
              display_title: 'Home',
              status: 'draft',
              locales: ['en'],
              updated_at: null,
            },
          ],
          total: 1,
          current_page: 1,
          per_page: 20,
        },
      },
      error: undefined,
    })
    const res = await fetchEntries({ type: 'page', page: 1, perPage: 20, q: 'ho' })
    expect(GET).toHaveBeenCalledWith('/entries', {
      params: { query: { type: 'page', page: 1, perPage: 20, q: 'ho' } },
    })
    expect(res.entries[0].display_title).toBe('Home')
    expect(res.total).toBe(1)
  })

  it('omits an empty q and defaults page fields', async () => {
    GET.mockResolvedValue({ data: { data: {} }, error: undefined })
    const res = await fetchEntries({ type: 'page', page: 2, perPage: 10, q: '' })
    expect(GET).toHaveBeenCalledWith('/entries', {
      params: { query: { type: 'page', page: 2, perPage: 10, q: undefined } },
    })
    expect(res.entries).toEqual([])
    expect(res.current_page).toBe(2)
  })

  it('throws on error', async () => {
    GET.mockResolvedValue({ data: undefined, error: { message: 'x' } })
    await expect(fetchEntries({ type: 'page', page: 1, perPage: 20 })).rejects.toBeTruthy()
  })
})
