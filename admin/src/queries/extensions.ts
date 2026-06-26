import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { authFetch } from '@/api/authFetch'
import { runtimeConfig } from '@/runtime/config'

// Extensions admin API (App\Http\Controllers\ExtensionAdminController, under /v1/admin/extensions).
// Installed data is local (PackageManifest + the enabled allow-list); Browse proxies Packagist
// filtered to type=glueful-extension. Enable/disable rewrites config/extensions.php (dev only).

export interface InstalledExtension {
  name: string
  provider: string
  version?: string | null
  description?: string | null
  author?: string | null
  requires_extensions: string[]
  enabled: boolean
}

export interface CatalogExtension {
  name: string
  description?: string | null
  url?: string | null
  repository?: string | null
  downloads: number
  favers: number
  installed: boolean
}

const base = () => `${runtimeConfig.apiBase}/extensions`

export async function fetchInstalledExtensions(): Promise<InstalledExtension[]> {
  const json = await authFetch(base())
  const data = (json.data ?? json) as Record<string, unknown>
  return Array.isArray(data.extensions) ? (data.extensions as InstalledExtension[]) : []
}

export function useInstalledExtensions() {
  return useQuery({
    key: () => ['extensions', 'installed'],
    query: fetchInstalledExtensions,
  })
}

export async function fetchExtensionCatalog(
  q?: string,
): Promise<{ results: CatalogExtension[]; available: boolean }> {
  const qs = q ? `?q=${encodeURIComponent(q)}` : ''
  const json = await authFetch(`${base()}/registry${qs}`)
  const data = (json.data ?? json) as Record<string, unknown>
  return {
    results: Array.isArray(data.results) ? (data.results as CatalogExtension[]) : [],
    available: data.available !== false,
  }
}

export function useExtensionCatalog(q: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['extensions', 'catalog', toValue(q) ?? ''],
    query: () => fetchExtensionCatalog(toValue(q)),
  })
}

export interface ExtensionReadme {
  found: boolean
  html: string | null
  source: string | null
}

export async function fetchExtensionReadme(name: string): Promise<ExtensionReadme> {
  const [vendor, pkg] = name.split('/')
  const json = await authFetch(
    `${base()}/${encodeURIComponent(vendor ?? '')}/${encodeURIComponent(pkg ?? '')}/readme`,
  )
  const d = (json.data ?? json) as Record<string, unknown>
  return {
    found: d.found === true,
    // Server-rendered + sanitized (CommonMark, raw HTML escaped, unsafe links blocked, images
    // stripped), so it is safe to render with v-html in the detail pane.
    html: typeof d.html === 'string' ? d.html : null,
    source: typeof d.source === 'string' ? d.source : null,
  }
}

export function useExtensionReadme(name: MaybeRefOrGetter<string | undefined>) {
  return useQuery({
    key: () => ['extensions', 'readme', toValue(name) ?? ''],
    query: () => fetchExtensionReadme(toValue(name) as string),
    enabled: () => !!toValue(name),
  })
}

export function useExtensionMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: ['extensions'] })

  const enable = useMutation({
    mutation: (name: string) =>
      authFetch(`${base()}/enable`, { method: 'POST', body: JSON.stringify({ name }) }),
    onSettled: invalidate,
  })
  const disable = useMutation({
    mutation: (name: string) =>
      authFetch(`${base()}/disable`, { method: 'POST', body: JSON.stringify({ name }) }),
    onSettled: invalidate,
  })

  return { enable, disable }
}

/** Short display name: `glueful/audit` → `audit`. */
export function extensionShortName(name: string): string {
  return name.split('/').pop() ?? name
}
