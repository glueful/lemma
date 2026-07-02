import { describe, it, expect, beforeEach } from 'vitest'
import { visibleNav, resetAdminModules } from '@/registry/adminModules'
import { registerNavigationModule } from '@/registry/navigationModule'

describe('navigation admin module gating (lemma.navigation capability)', () => {
  beforeEach(() => resetAdminModules())

  it('omits the Navigation nav when lemma.navigation is disabled', () => {
    registerNavigationModule()
    const [main] = visibleNav(() => false)
    expect(main).toEqual([])
  })

  it('includes the Navigation nav linking to /navigation when enabled', () => {
    registerNavigationModule()
    const [main] = visibleNav((id) => id === 'lemma.navigation')
    expect(main.map((i) => i.label)).toEqual(['Navigation'])
    expect(main[0].to).toBe('/navigation')
  })
})
