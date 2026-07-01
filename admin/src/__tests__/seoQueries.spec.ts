import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { fetchSeoMeta, saveSeoMeta } from '@/queries/seo'

describe('seo query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetchSeoMeta GETs /seo/meta/{uuid}?locale= and unwraps data.data', async () => {
    authFetch.mockResolvedValue({ data: { title: 'Hi', robots: 'index' } })
    const meta = await fetchSeoMeta('e-1', 'en')
    expect(meta).toEqual({ title: 'Hi', robots: 'index' })
    const url = authFetch.mock.calls[0][0] as string
    expect(url).toContain('/v1/admin/seo/meta/e-1?')
    expect(url).toContain('locale=en')
  })

  it('fetchSeoMeta returns {} when the override is unset (empty body)', async () => {
    authFetch.mockResolvedValue({})
    expect(await fetchSeoMeta('e-1', 'en')).toEqual({})
  })

  it('saveSeoMeta PUTs the payload as JSON to the same path+locale', async () => {
    authFetch.mockResolvedValue({})
    await saveSeoMeta('e-1', 'en', { title: 'T', robots: 'noindex' })
    const [url, init] = authFetch.mock.calls[0] as [string, RequestInit]
    expect(url).toContain('/v1/admin/seo/meta/e-1?')
    expect(url).toContain('locale=en')
    expect(init.method).toBe('PUT')
    expect(JSON.parse(init.body as string)).toEqual({ title: 'T', robots: 'noindex' })
  })
})
