import type { NavigationMenuItem } from '@nuxt/ui'

export interface AdminModuleNav {
  main?: NavigationMenuItem[]
  utilities?: NavigationMenuItem[]
}

export interface AdminModule {
  id: string
  /** Capability ids that must ALL be enabled for this module to be visible. Empty/absent = always-on. */
  requires?: string[]
  nav?: AdminModuleNav
}

const modules: AdminModule[] = []

export function registerAdminModule(module: AdminModule): void {
  const i = modules.findIndex((m) => m.id === module.id)
  if (i >= 0) modules[i] = module
  else modules.push(module)
}

export function registeredModules(): AdminModule[] {
  return modules
}

export function resetAdminModules(): void {
  modules.length = 0
}

function moduleEnabled(module: AdminModule, isEnabled: (id: string) => boolean): boolean {
  return (module.requires ?? []).every((id) => isEnabled(id))
}

/** Assemble the two-group sidebar ([main, utilities]) from the enabled modules, in registration order. */
export function visibleNav(
  isEnabled: (id: string) => boolean,
): [NavigationMenuItem[], NavigationMenuItem[]] {
  const main: NavigationMenuItem[] = []
  const utilities: NavigationMenuItem[] = []
  for (const m of modules) {
    if (!moduleEnabled(m, isEnabled)) continue
    if (m.nav?.main) main.push(...m.nav.main)
    if (m.nav?.utilities) utilities.push(...m.nav.utilities)
  }
  return [main, utilities]
}
