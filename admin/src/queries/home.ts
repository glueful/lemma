import { useQuery } from '@pinia/colada'
import { fetchContentTypes } from './contentTypes'
import { fetchEntries, type EntryListRow } from './entries'
import { qk } from './keys'

// The Home dashboard's data. There's no dedicated overview endpoint, so we derive it: fetch the
// content types, then one small page per type. Because the entry list is ordered updated_at DESC,
// that single page yields BOTH the type's entry count (total) and its most-recent rows in one
// request — no separate count call needed.

export interface HomeTypeSummary {
  slug: string
  name: string
  count: number
}

export interface HomeRecentEntry extends EntryListRow {
  type_slug: string
  type_name: string
}

export interface HomeOverview {
  types: HomeTypeSummary[]
  recent: HomeRecentEntry[]
  total_entries: number
}

const RECENT_PER_TYPE = 5
const RECENT_LIMIT = 8

export async function fetchHomeOverview(): Promise<HomeOverview> {
  const types = await fetchContentTypes()

  const perType = await Promise.all(
    types.map(async (t) => {
      const slug = String(t.slug ?? '')
      const name = String(t.name ?? slug)
      try {
        const page = await fetchEntries({ type: slug, page: 1, perPage: RECENT_PER_TYPE })
        return {
          slug,
          name,
          count: page.total,
          recent: page.entries.map(
            (e): HomeRecentEntry => ({ ...e, type_slug: slug, type_name: name }),
          ),
        }
      } catch {
        // A single type failing (e.g. a permission edge) shouldn't blank the whole dashboard.
        return { slug, name, count: 0, recent: [] as HomeRecentEntry[] }
      }
    }),
  )

  const recent = perType
    .flatMap((t) => t.recent)
    .sort((a, b) => (b.updated_at ?? '').localeCompare(a.updated_at ?? ''))
    .slice(0, RECENT_LIMIT)

  return {
    types: perType.map(({ slug, name, count }) => ({ slug, name, count })),
    recent,
    total_entries: perType.reduce((sum, t) => sum + t.count, 0),
  }
}

export function useHomeOverview() {
  return useQuery({ key: qk.home(), query: fetchHomeOverview })
}
