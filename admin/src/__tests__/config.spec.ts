import { describe, it, expect, vi } from 'vitest'

describe('runtime config loader', () => {
  it('fetches /admin/config.json and exposes typed fields', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(
        new Response(
          JSON.stringify({
            apiBase: '/v1/admin',
            sitePreviewUrl: 'https://x/preview',
            defaultLocale: 'en',
            installed: true,
          }),
          { status: 200 },
        ),
      ),
    )
    const { loadRuntimeConfig } = await import('@/runtime/config')
    const cfg = await loadRuntimeConfig()
    expect(cfg.apiBase).toBe('/v1/admin')
    expect(cfg.installed).toBe(true)
  })
})
