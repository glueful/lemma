import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

export interface RedirectRow {
  uuid: string
  source_slug: string
  status: number
  target_state?: string
  [k: string]: unknown
}

export interface CreateRedirectInput {
  locale: string
  source_slug: string
  status: number
  url: string
}

// The redirects-list response body isn't typed in the spec; cast to the known contract.
export async function fetchRedirects(typeSlug: string): Promise<RedirectRow[]> {
  const { data, error, response } = await client.GET('/content-types/{slug}/redirects', {
    params: { path: { slug: typeSlug } },
  })
  if (error) throw toApiError(error, response)
  return (
    (data as unknown as { data?: { redirects?: RedirectRow[] } } | undefined)?.data?.redirects ?? []
  )
}

export function useRedirects(typeSlug: MaybeRefOrGetter<string>) {
  return useQuery({
    key: () => qk.redirects(toValue(typeSlug)),
    query: () => fetchRedirects(toValue(typeSlug)),
  })
}

export async function createRedirect(typeSlug: string, input: CreateRedirectInput) {
  const { data, error, response } = await client.POST('/content-types/{slug}/redirects', {
    params: { path: { slug: typeSlug } },
    body: {
      locale: input.locale,
      source_slug: input.source_slug,
      status: input.status,
      // target is mistyped as unknown[] in the spec; the backend expects { url } | { entry_uuid }.
      target: { url: input.url } as unknown as unknown[],
    },
  })
  if (error) throw toApiError(error, response)
  return data
}

export async function deleteRedirect(redirectUuid: string) {
  const { data, error, response } = await client.DELETE('/redirects/{uuid}', {
    params: { path: { uuid: redirectUuid } },
  })
  if (error) throw toApiError(error, response)
  return data
}

export function useRedirectMutations(typeSlug: string) {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qk.redirects(typeSlug) })
  const create = useMutation({
    mutation: (input: CreateRedirectInput) => createRedirect(typeSlug, input),
    onSettled: invalidate,
  })
  const remove = useMutation({
    mutation: (redirectUuid: string) => deleteRedirect(redirectUuid),
    onSettled: invalidate,
  })
  return { create, remove }
}
