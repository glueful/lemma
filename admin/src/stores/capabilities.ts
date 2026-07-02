import { defineStore } from 'pinia'
import { ref } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

interface CapabilityRow {
  id: string
}

/**
 * Enabled capability ids, loaded post-auth from GET /v1/admin/capabilities (Phase B).
 * Drives capability-gated nav (the admin module registry) and route gating. Fails closed:
 * on error the enabled set is empty, so nothing pack-gated shows while core stays visible.
 */
export const useCapabilitiesStore = defineStore('capabilities', () => {
  const enabledIds = ref<Set<string>>(new Set())
  const loaded = ref(false)
  let inflight: Promise<void> | null = null

  function isEnabled(id: string): boolean {
    return enabledIds.value.has(id)
  }

  async function load(): Promise<void> {
    try {
      const json = await authFetch(`${runtimeConfig.apiBase}/capabilities`)
      const data = (json.data ?? json) as Record<string, unknown>
      const rows = Array.isArray(data.capabilities) ? (data.capabilities as CapabilityRow[]) : []
      enabledIds.value = new Set(rows.map((r) => r.id))
    } catch {
      enabledIds.value = new Set()
    } finally {
      loaded.value = true
    }
  }

  function ensureLoaded(): Promise<void> {
    if (loaded.value) return Promise.resolve()
    inflight ??= load().finally(() => {
      inflight = null
    })
    return inflight
  }

  // Clear the cached set so the next ensureLoaded() reloads. Called on login/logout so a second
  // account in the same tab (SPA nav, no reload) never inherits the previous user's capabilities.
  function reset(): void {
    enabledIds.value = new Set()
    loaded.value = false
    inflight = null
  }

  return { enabledIds, loaded, isEnabled, load, ensureLoaded, reset }
})
