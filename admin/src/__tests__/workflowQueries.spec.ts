import { describe, it, expect, vi, beforeEach } from 'vitest'

const authFetch = vi.fn()
vi.mock('@/api/authFetch', () => ({ authFetch: (...a: unknown[]) => authFetch(...a) }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { fetchWorkflowState, transitionWorkflow, fetchWorkflowQueue } from '@/queries/workflow'

describe('workflow query layer', () => {
  beforeEach(() => authFetch.mockReset())

  it('fetchWorkflowState hits /workflow/entries/{uuid}/{locale} and unwraps data', async () => {
    authFetch.mockResolvedValue({ data: { state: 'in_review', history: [] } })
    const s = await fetchWorkflowState('e-1', 'en')
    expect(s.state).toBe('in_review')
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/workflow/entries/e-1/en')
  })

  it('transitionWorkflow POSTs the action path and includes the note when given', async () => {
    authFetch.mockResolvedValue({ data: { state: 'changes_requested', history: [] } })
    const s = await transitionWorkflow('e-1', 'en', 'request-changes', 'tighten intro')
    expect(s.state).toBe('changes_requested')
    const [url, init] = authFetch.mock.calls[0] as [string, { method: string; body: string }]
    expect(url).toBe('/v1/admin/workflow/entries/e-1/en/request-changes')
    expect(init.method).toBe('POST')
    expect(JSON.parse(init.body)).toEqual({ note: 'tighten intro' })
  })

  it('transitionWorkflow sends an empty body when no note is given', async () => {
    authFetch.mockResolvedValue({ data: { state: 'in_review', history: [] } })
    await transitionWorkflow('e-1', 'en', 'submit')
    const [, init] = authFetch.mock.calls[0] as [string, { body: string }]
    expect(JSON.parse(init.body)).toEqual({})
  })

  it('fetchWorkflowQueue hits /workflow/queue with the page and normalizes the payload', async () => {
    authFetch.mockResolvedValue({
      data: {
        items: [{ entry_uuid: 'e-1', locale: 'en', title: 'Hello', type_slug: 'blog' }],
        total: 1,
        page: 1,
        perPage: 25,
      },
    })
    const q = await fetchWorkflowQueue(2)
    expect(q.total).toBe(1)
    expect(q.items[0]!.title).toBe('Hello')
    expect(authFetch.mock.calls[0][0]).toBe('/v1/admin/workflow/queue?page=2')
  })

  it('fetchWorkflowQueue tolerates an empty payload', async () => {
    authFetch.mockResolvedValue({ data: {} })
    const q = await fetchWorkflowQueue()
    expect(q).toEqual({ items: [], total: 0, page: 1, perPage: 25 })
  })
})
