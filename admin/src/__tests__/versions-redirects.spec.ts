import { describe, it, expect, vi, beforeEach } from 'vitest'

const { GET, POST, DELETE } = vi.hoisted(() => ({ GET: vi.fn(), POST: vi.fn(), DELETE: vi.fn() }))
vi.mock('@/api/client', () => ({ client: { GET, POST, DELETE } }))

import { fetchVersions, rollbackEntry } from '@/queries/versions'
import { fetchRedirects, createRedirect, deleteRedirect } from '@/queries/redirects'

describe('F5 versions + redirects queries', () => {
  beforeEach(() => {
    GET.mockReset()
    POST.mockReset()
    DELETE.mockReset()
  })

  it('fetchVersions returns data.versions', async () => {
    GET.mockResolvedValue({
      data: { data: { versions: [{ uuid: 'v1', version: 2 }] } },
      error: undefined,
    })
    expect(await fetchVersions('e1', 'en')).toEqual([{ uuid: 'v1', version: 2 }])
  })

  it('rollbackEntry POSTs the version_uuid', async () => {
    POST.mockResolvedValue({ data: {}, error: undefined })
    await rollbackEntry('e1', 'en', 'v1')
    expect(POST).toHaveBeenCalledWith('/entries/{uuid}/rollback/{locale}', {
      params: { path: { uuid: 'e1', locale: 'en' } },
      body: { version_uuid: 'v1' },
    })
  })

  it('fetchRedirects returns data.redirects', async () => {
    GET.mockResolvedValue({
      data: { data: { redirects: [{ uuid: 'r1', source_slug: 'old', status: 301 }] } },
      error: undefined,
    })
    expect((await fetchRedirects('page'))[0].source_slug).toBe('old')
  })

  it('createRedirect POSTs source/target/status; deleteRedirect DELETEs', async () => {
    POST.mockResolvedValue({ data: {}, error: undefined })
    DELETE.mockResolvedValue({ data: {}, error: undefined })
    await createRedirect('page', { locale: 'en', source_slug: 'old', status: 301, url: '/new' })
    expect(POST).toHaveBeenCalledWith('/content-types/{slug}/redirects', {
      params: { path: { slug: 'page' } },
      body: { locale: 'en', source_slug: 'old', status: 301, target: { url: '/new' } },
    })
    await deleteRedirect('r1')
    expect(DELETE).toHaveBeenCalledWith('/redirects/{uuid}', { params: { path: { uuid: 'r1' } } })
  })
})
