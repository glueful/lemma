import { useMutation } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { runtimeConfig } from '@/runtime/config'

// Mints a short-lived preview token for the entry's current draft (in the given locale).
export async function mintPreview(uuid: string, locale: string): Promise<string> {
  const { data, error, response } = await client.POST('/entries/{uuid}/preview/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  return data?.data?.token ?? ''
}

// Builds the frontend preview URL from the configured template + the minted token.
export function buildPreviewUrl(token: string): string {
  const base = runtimeConfig.sitePreviewUrl
  if (!base || !token) return ''
  const sep = base.includes('?') ? '&' : '?'
  return `${base}${sep}token=${encodeURIComponent(token)}`
}

export function usePreview(uuid: string, locale: string) {
  return useMutation({
    mutation: () => mintPreview(uuid, locale),
  })
}

export interface PreviewMintResult {
  token: string
  themeUrl: string | null
}

// Mints a preview token; theme_url is server-decided (null = rendered delivery off).
export async function mintPreviewData(uuid: string, locale: string): Promise<PreviewMintResult> {
  const { data, error, response } = await client.POST('/entries/{uuid}/preview/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw toApiError(error, response)
  return { token: data?.data?.token ?? '', themeUrl: data?.data?.theme_url ?? null }
}

export function useThemePreview(uuid: string, locale: string) {
  return useMutation({
    mutation: () => mintPreviewData(uuid, locale),
  })
}
