import { client } from '@/api/client'
import { toApiError } from '@/api/errors'

export interface LocaleUsage {
  published_entries: number
  draft_entries: number
}

export async function fetchLocaleUsage(locale: string): Promise<LocaleUsage> {
  const { data, error, response } = await client.GET('/locales/{locale}/usage', {
    params: { path: { locale } },
  })
  if (error) throw toApiError(error, response)
  const d = data?.data as Partial<LocaleUsage> | undefined
  return { published_entries: d?.published_entries ?? 0, draft_entries: d?.draft_entries ?? 0 }
}
