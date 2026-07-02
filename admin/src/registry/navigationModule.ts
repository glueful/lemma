import type { NavigationMenuItem } from '@nuxt/ui'
import { registerAdminModule } from './adminModules'

// Navigation (menu builder) nav — gated on the `lemma.navigation` capability; disappears
// when the pack is disabled or removed (the backend 404s those routes too — see the
// pack's NavigationRemovabilityTest).
const main: NavigationMenuItem[] = [
  {
    label: 'Navigation',
    icon: 'i-lucide-menu',
    to: '/navigation',
  },
]

export function registerNavigationModule(): void {
  registerAdminModule({ id: 'navigation', requires: ['lemma.navigation'], nav: { main } })
}
