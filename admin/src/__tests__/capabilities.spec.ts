import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

const { authFetch } = vi.hoisted(() => ({ authFetch: vi.fn() }))
vi.mock('@/api/authFetch', () => ({ authFetch }))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { useCapabilitiesStore } from '@/stores/capabilities'

describe('capabilities store', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    authFetch.mockReset()
  })

  it('loads enabled capability ids from the endpoint', async () => {
    authFetch.mockResolvedValue({
      data: { capabilities: [{ id: 'lemma.forms' }, { id: 'lemma.render' }] },
    })
    const store = useCapabilitiesStore()
    expect(store.loaded).toBe(false)
    await store.load()
    expect(authFetch).toHaveBeenCalledWith('/v1/admin/capabilities')
    expect(store.loaded).toBe(true)
    expect(store.isEnabled('lemma.forms')).toBe(true)
    expect(store.isEnabled('lemma.render')).toBe(true)
    expect(store.isEnabled('lemma.nope')).toBe(false)
  })

  it('fails closed on error (empty enabled set, loaded=true)', async () => {
    authFetch.mockRejectedValue(new Error('403'))
    const store = useCapabilitiesStore()
    await store.load()
    expect(store.loaded).toBe(true)
    expect(store.isEnabled('lemma.forms')).toBe(false)
  })

  it('ensureLoaded loads at most once', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [] } })
    const store = useCapabilitiesStore()
    await Promise.all([store.ensureLoaded(), store.ensureLoaded()])
    await store.ensureLoaded()
    expect(authFetch).toHaveBeenCalledTimes(1)
  })

  // Regression: reset() must drop the cached set AND clear the loaded flag so the next
  // ensureLoaded() re-fetches — otherwise a second account in the same tab inherits the
  // previous user's capabilities.
  it('reset clears the set and forces the next ensureLoaded to reload', async () => {
    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'lemma.forms' }] } })
    const store = useCapabilitiesStore()
    await store.ensureLoaded()
    expect(store.isEnabled('lemma.forms')).toBe(true)

    store.reset()
    expect(store.loaded).toBe(false)
    expect(store.isEnabled('lemma.forms')).toBe(false)

    authFetch.mockResolvedValue({ data: { capabilities: [{ id: 'lemma.render' }] } })
    await store.ensureLoaded()
    expect(authFetch).toHaveBeenCalledTimes(2)
    expect(store.isEnabled('lemma.forms')).toBe(false)
    expect(store.isEnabled('lemma.render')).toBe(true)
  })
})
