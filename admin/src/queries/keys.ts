// Central cache namespace. Every Colada query keys off these so invalidation is exhaustive and
// typo-proof. Keys are MaybeRefOrGetter-friendly (Pinia Colada): pass getters where a param is
// reactive (e.g. () => ['entries', typeSlug.value]).
export const qk = {
  contentTypes: () => ['content-types'] as const,
  entries: (type: string) => ['entries', type] as const,
  entry: (uuid: string) => ['entry', uuid] as const,
  draft: (uuid: string, locale: string) => ['draft', uuid, locale] as const,
  routes: (uuid: string) => ['routes', uuid] as const,
  schedules: (uuid: string) => ['schedules', uuid] as const,
  versions: (uuid: string) => ['versions', uuid] as const,
  redirects: (type: string) => ['redirects', type] as const,
}
