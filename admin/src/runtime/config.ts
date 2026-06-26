export interface RuntimeConfig {
  // Admin API base PATH the typed client calls. The app is served same-origin (the PHP app serves
  // both the SPA at /admin and the API), so every call is a relative path against the page.
  apiBase: string
  sitePreviewUrl: string
  defaultLocale: string
  installed: boolean
}

// Filled by loadRuntimeConfig() before app.mount (main.ts). Exported as a mutable singleton so the
// client/stores can read it synchronously after boot. Defaults are safe pre-load values.
export const runtimeConfig: RuntimeConfig = {
  apiBase: '/v1/admin',
  sitePreviewUrl: '',
  defaultLocale: 'en',
  installed: false,
}

async function fetchConfig(url: string): Promise<Partial<RuntimeConfig>> {
  const res = await fetch(url, { headers: { accept: 'application/json' } })
  if (!res.ok) throw new Error(`runtime config ${res.status}`)
  return (await res.json()) as Partial<RuntimeConfig>
}

function applyConfig(data: Partial<RuntimeConfig>): void {
  Object.assign(runtimeConfig, {
    apiBase: data.apiBase ?? runtimeConfig.apiBase,
    sitePreviewUrl: data.sitePreviewUrl ?? runtimeConfig.sitePreviewUrl,
    defaultLocale: data.defaultLocale ?? runtimeConfig.defaultLocale,
    installed: Boolean(data.installed),
  })
}

export async function loadRuntimeConfig(): Promise<RuntimeConfig> {
  // The admin SPA is served same-origin by the PHP app (serveFrontend at /admin), so /admin/config —
  // the backend's dynamic runtime-config route — is fetched relative. It returns the live values the
  // SPA needs at boot: apiBase, sitePreviewUrl, defaultLocale, and installed.
  applyConfig(await fetchConfig('/admin/config'))
  return runtimeConfig
}
