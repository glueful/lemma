import type { EntryLocaleSummary } from '@/queries/entries'

export type LocaleStatusKey = 'published' | 'scheduled' | 'draft' | 'none'

export interface LocaleStatus {
  key: LocaleStatusKey
  label: string
  icon: string
  /** Nuxt UI semantic color for badges/icons. */
  color: 'success' | 'warning' | 'neutral'
  /** Explicit Tailwind text-color class (avoids runtime string interpolation which Tailwind cannot JIT-scan). */
  textClass: 'text-success' | 'text-warning' | 'text-neutral'
}

const STATUSES: Record<LocaleStatusKey, Omit<LocaleStatus, 'key'>> = {
  published: {
    label: 'Published',
    icon: 'i-lucide-check-circle',
    color: 'success',
    textClass: 'text-success',
  },
  scheduled: {
    label: 'Scheduled',
    icon: 'i-lucide-clock',
    color: 'warning',
    textClass: 'text-warning',
  },
  draft: { label: 'Draft', icon: 'i-lucide-pencil', color: 'neutral', textClass: 'text-neutral' },
  none: {
    label: 'Not started',
    icon: 'i-lucide-circle-dashed',
    color: 'neutral',
    textClass: 'text-neutral',
  },
}

/** Derive a single editorial status for a locale. Published wins; then a pending schedule; then a bare draft. */
export function localeStatus(summary: EntryLocaleSummary): LocaleStatus {
  let key: LocaleStatusKey = 'none'
  if (summary.is_published) key = 'published'
  else if (summary.scheduled && (summary.scheduled.publish || summary.scheduled.unpublish))
    key = 'scheduled'
  else if (summary.has_draft) key = 'draft'
  return { key, ...STATUSES[key] }
}
