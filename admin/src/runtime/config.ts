export interface RuntimeConfig {
  apiBase: string
  sitePreviewUrl: string
  defaultLocale: string
  installed: boolean
}

// Filled by loadRuntimeConfig() before app.mount (main.ts). Exported as a mutable singleton so
// the client/stores can read it synchronously after boot. Defaults are safe pre-load values.
export const runtimeConfig: RuntimeConfig = {
  apiBase: '/v1/admin',
  sitePreviewUrl: '',
  defaultLocale: 'en',
  installed: false,
}

export async function loadRuntimeConfig(): Promise<RuntimeConfig> {
  const res = await fetch('/admin/config.json', { headers: { accept: 'application/json' } })
  if (!res.ok) throw new Error(`config.json ${res.status}`)
  const data = (await res.json()) as Partial<RuntimeConfig>
  Object.assign(runtimeConfig, {
    apiBase: data.apiBase ?? runtimeConfig.apiBase,
    sitePreviewUrl: data.sitePreviewUrl ?? '',
    defaultLocale: data.defaultLocale ?? 'en',
    installed: Boolean(data.installed),
  })
  return runtimeConfig
}
