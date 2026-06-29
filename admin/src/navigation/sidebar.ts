import { computed, ref, type ComputedRef } from 'vue'
import type { NavigationMenuItem } from '@nuxt/ui'
import { visibleNav } from '@/registry/adminModules'
import { useCapabilitiesStore } from '@/stores/capabilities'

export const open = ref(false)

/** The two-group sidebar nav ([main, utilities]), filtered by enabled capabilities (reactive). */
export function useVisibleNav(): ComputedRef<[NavigationMenuItem[], NavigationMenuItem[]]> {
  const caps = useCapabilitiesStore()
  return computed(() => visibleNav((id) => caps.isEnabled(id)))
}
