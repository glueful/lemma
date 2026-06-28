import { describe, expect, it } from 'vitest'
import { localeStatus } from './localeStatus'
import type { EntryLocaleSummary } from '@/queries/entries'

function summary(over: Partial<EntryLocaleSummary>): EntryLocaleSummary {
  return {
    locale: 'en',
    has_draft: false,
    is_published: false,
    route_slug: null,
    draft_updated_at: null,
    published_at: null,
    scheduled: null,
    ...over,
  }
}

describe('localeStatus', () => {
  it('reports published', () => {
    expect(localeStatus(summary({ is_published: true })).key).toBe('published')
  })
  it('reports scheduled when a publish schedule is pending', () => {
    const s = summary({
      has_draft: true,
      scheduled: { publish: '2026-07-01T09:00:00Z', unpublish: null, last_failure: null },
    })
    expect(localeStatus(s).key).toBe('scheduled')
  })
  it('reports draft when a draft exists but is unpublished', () => {
    expect(localeStatus(summary({ has_draft: true })).key).toBe('draft')
  })
  it('reports none when nothing exists', () => {
    expect(localeStatus(summary({})).key).toBe('none')
  })
  it('prefers published over a pending schedule', () => {
    const s = summary({
      is_published: true,
      scheduled: { publish: null, unpublish: '2026-07-01T09:00:00Z', last_failure: null },
    })
    expect(localeStatus(s).key).toBe('published')
  })
})
