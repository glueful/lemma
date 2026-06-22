import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

export interface RouteRow {
  locale: string
  slug: string
  [k: string]: unknown
}

// The routes-list response body isn't typed in the spec (content?: never), so we cast to the
// known contract (data.routes[]).
export async function fetchRoutes(uuid: string): Promise<RouteRow[]> {
  const { data, error, response } = await client.GET('/entries/{uuid}/routes', {
    params: { path: { uuid } },
  })
  if (error) throw toApiError(error, response)
  return (data as unknown as { data?: { routes?: RouteRow[] } } | undefined)?.data?.routes ?? []
}

export function useRoutes(uuid: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.routes(toValue(uuid)),
    query: () => fetchRoutes(toValue(uuid)),
  })
}

export async function saveRoute(uuid: string, locale: string, slug: string) {
  const { data, error, response } = await client.PUT('/entries/{uuid}/routes/{locale}', {
    params: { path: { uuid, locale } },
    body: { slug },
  })
  if (error) throw toApiError(error, response)
  return data
}

export function useSaveRoute(uuid: string, locale: string) {
  const cache = useQueryCache()
  return useMutation({
    mutation: (slug: string) => saveRoute(uuid, locale, slug),
    onSettled() {
      cache.invalidateQueries({ key: qk.routes(uuid) })
    },
  })
}
