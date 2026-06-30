import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin' },
}))
vi.mock('@/stores/session', () => ({
  useSessionStore: () => ({ accessToken: null, refresh: vi.fn(), clear: vi.fn() }),
}))

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  })
}

// The api client captures globalThis.fetch at creation, so stub fetch BEFORE importing the
// fetcher (reset the module graph each test, then dynamic-import after stubbing).
describe('collections query layer', () => {
  beforeEach(() => {
    vi.resetModules()
    vi.stubGlobal('fetch', vi.fn())
  })

  it('parses and normalizes the collection list', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({
        data: {
          collections: [
            {
              name: 'posts',
              label: 'Posts',
              fields: [{ name: 'title', type: 'collections.text', settings: {} }],
              accessPolicy: { read: 'public', write: 'scoped', delete: 'scoped' },
            },
          ],
        },
      }),
    )

    const { fetchCollections } = await import('@/queries/collections')
    const collections = await fetchCollections()

    expect(collections).toHaveLength(1)
    expect(collections[0].name).toBe('posts')
    expect(collections[0].fields[0].type).toBe('collections.text')
    expect(collections[0].accessPolicy.read).toBe('public')
    expect(collections[0].accessPolicy.write).toBe('scoped')
  })

  it('defaults a missing access policy to all-scoped', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ data: { collections: [{ name: 'bare', label: 'Bare' }] } }),
    )

    const { fetchCollections } = await import('@/queries/collections')
    const [collection] = await fetchCollections()

    expect(collection.accessPolicy).toEqual({ read: 'scoped', write: 'scoped', delete: 'scoped' })
    expect(collection.fields).toEqual([])
  })

  it('throws ApiError on a failed response', async () => {
    ;(globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
      jsonResponse({ message: 'Forbidden' }, 403),
    )

    const { fetchCollections } = await import('@/queries/collections')
    const { ApiError } = await import('@/api/errors')
    await expect(fetchCollections()).rejects.toBeInstanceOf(ApiError)
  })
})
