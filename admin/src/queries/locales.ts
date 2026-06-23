import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { authFetch } from '@/api/authFetch'

// Locale management via the glueful/i18n extension's /i18n/locales endpoints (root path, NOT under
// /v1/admin). The list response isn't typed in the spec, so we pin the contract here (mirrors the
// i18n `locales` table). There is no DELETE endpoint — locales are disabled, not removed.

export interface Locale {
  code: string
  name: string
  native_name?: string | null
  enabled: boolean
  is_default: boolean
  direction: 'ltr' | 'rtl'
  fallback_locale?: string | null
  region?: string | null
}

export interface CreateLocaleInput {
  code: string
  name: string
  native_name?: string
  direction?: 'ltr' | 'rtl'
  enabled?: boolean
  is_default?: boolean
}

/** Update accepts the same fields except `code`, which is immutable. */
export type UpdateLocaleInput = Partial<Omit<CreateLocaleInput, 'code'>>

const LOCALES = '/i18n/locales'
const qkLocales = () => ['i18n', 'locales'] as const

export async function fetchLocales(): Promise<Locale[]> {
  const json = await authFetch(LOCALES)
  return (json.data as { locales?: Locale[] } | undefined)?.locales ?? []
}

export function useLocales() {
  return useQuery({ key: qkLocales(), query: fetchLocales })
}

export function useLocaleMutations() {
  const cache = useQueryCache()
  const invalidate = () => cache.invalidateQueries({ key: qkLocales() })

  const create = useMutation({
    mutation: (input: CreateLocaleInput) =>
      authFetch(LOCALES, { method: 'POST', body: JSON.stringify(input) }),
    onSettled: invalidate,
  })

  const update = useMutation({
    mutation: (vars: { code: string; input: UpdateLocaleInput }) =>
      authFetch(`${LOCALES}/${encodeURIComponent(vars.code)}`, {
        method: 'PATCH',
        body: JSON.stringify(vars.input),
      }),
    onSettled: invalidate,
  })

  return { create, update }
}
