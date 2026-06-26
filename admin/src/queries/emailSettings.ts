import { useMutation, useQuery, useQueryCache } from '@pinia/colada'
import { runtimeConfig } from '@/runtime/config'
import { authFetch } from '@/api/authFetch'

// The /v1/admin/settings/email endpoints aren't in the generated OpenAPI schema yet, so we call
// them with an authenticated raw fetch instead of the typed client.
const base = () => `${runtimeConfig.apiBase}/settings/email`

export interface EmailSettings {
  mailer: string
  host: string
  port: string
  username: string
  encryption: string
  from: string
  from_name: string
  bcc: string
  logo_url: string
  /** The password is never returned — only whether one is currently set. */
  password_set: boolean
}

/** The editable fields plus the write-only password (sent only when non-empty). */
export type EmailSettingsInput = Omit<EmailSettings, 'password_set'> & { password?: string }

export async function fetchEmailSettings(): Promise<EmailSettings> {
  const json = await authFetch(base())
  return (json.data as { settings?: EmailSettings } | undefined)?.settings ?? ({} as EmailSettings)
}

export function useEmailSettings() {
  return useQuery({ key: ['settings', 'email'], query: fetchEmailSettings })
}

export function useEmailSettingsMutations() {
  const cache = useQueryCache()

  const save = useMutation({
    mutation: (input: EmailSettingsInput) =>
      authFetch(base(), { method: 'PUT', body: JSON.stringify(input) }),
    onSettled() {
      cache.invalidateQueries({ key: ['settings', 'email'] })
    },
  })

  const test = useMutation({
    mutation: (to: string) =>
      authFetch(`${base()}/test`, { method: 'POST', body: JSON.stringify({ to }) }),
  })

  return { save, test }
}
