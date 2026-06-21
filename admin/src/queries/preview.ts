import { useMutation } from '@pinia/colada'
import { client } from '@/api/client'
import { runtimeConfig } from '@/runtime/config'

// Mints a short-lived preview token for the entry's current draft (in the given locale).
export async function mintPreview(uuid: string, locale: string): Promise<string> {
  const { data, error } = await client.POST('/entries/{uuid}/preview/{locale}', {
    params: { path: { uuid, locale } },
  })
  if (error) throw error
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
