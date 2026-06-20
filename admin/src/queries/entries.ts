import { useQuery } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { qk } from './keys'

// The OpenAPI spec types entry rows as `unknown[]`, so we pin the known contract here
// (EntryRepository::listForType / EntryListItemData on the backend).
export interface EntryListRow {
  uuid: string
  display_title: string
  status: 'draft' | 'scheduled' | 'published' | (string & {})
  locales: string[]
  updated_at: string | null
}

export interface EntryListPage {
  entries: EntryListRow[]
  total: number
  current_page: number
  per_page: number
}

export async function fetchEntries(params: {
  type: string
  page: number
  perPage: number
  q?: string
}): Promise<EntryListPage> {
  const { data, error } = await client.GET('/entries', {
    params: {
      query: {
        type: params.type,
        page: params.page,
        perPage: params.perPage,
        q: params.q || undefined,
      },
    },
  })
  if (error) throw error
  const d = data?.data
  return {
    entries: (d?.entries ?? []) as EntryListRow[],
    total: d?.total ?? 0,
    current_page: d?.current_page ?? params.page,
    per_page: d?.per_page ?? params.perPage,
  }
}

export function useEntries(
  type: MaybeRefOrGetter<string>,
  page: MaybeRefOrGetter<number>,
  perPage: MaybeRefOrGetter<number>,
  q: MaybeRefOrGetter<string | undefined>,
) {
  return useQuery({
    // page + q are part of the key so each page/filter is cached independently and refetches on change.
    key: () => [...qk.entries(toValue(type)), toValue(page), toValue(q) ?? ''],
    query: () =>
      fetchEntries({
        type: toValue(type),
        page: toValue(page),
        perPage: toValue(perPage),
        q: toValue(q),
      }),
  })
}
