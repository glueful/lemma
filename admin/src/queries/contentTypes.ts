import { useQuery } from '@pinia/colada'
import { client } from '@/api/client'
import { toApiError } from '@/api/errors'
import { qk } from './keys'

/**
 * Fetches the admin content-type list. Extracted from the query wrapper so it can be unit-tested
 * without a Pinia Colada runtime.
 */
export async function fetchContentTypes() {
  const { data, error, response } = await client.GET('/content-types')
  if (error) throw toApiError(error, response)
  return data?.data?.content_types ?? []
}

export function useContentTypes() {
  return useQuery({
    key: qk.contentTypes(),
    query: fetchContentTypes,
  })
}
