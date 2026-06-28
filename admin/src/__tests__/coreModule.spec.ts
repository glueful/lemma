import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { resetAdminModules, visibleNav } from '@/registry/adminModules'

vi.mock('@/api/authFetch', () => ({
  authFetch: vi.fn().mockResolvedValue({ data: { capabilities: [] } }),
}))
vi.mock('@/runtime/config', () => ({ runtimeConfig: { apiBase: '/v1/admin' } }))

import { registerCoreModule } from '@/registry/coreModule'

describe('core module registration', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    resetAdminModules()
  })

  it('registers the core nav as an always-on module (visible with no capabilities)', () => {
    registerCoreModule()
    const [main, utilities] = visibleNav(() => false) // no caps enabled
    // Core is always-on: its top-level sections are present even with zero enabled capabilities.
    const labels = main.map((i) => i.label)
    expect(labels).toContain('Home')
    expect(labels).toContain('Content')
    expect(labels).toContain('Media')
    // Utilities is a node INSIDE the single (main) group today — assert it stays there.
    expect(labels).toContain('Utilities')
    // The second group is empty (no items[1] exists today) — preserves the empty bottom menu.
    expect(utilities).toEqual([])
  })

  it('is idempotent (re-registering does not duplicate the core module)', () => {
    registerCoreModule()
    registerCoreModule()
    const [main] = visibleNav(() => true)
    expect(main.filter((i) => i.label === 'Home')).toHaveLength(1)
  })
})
