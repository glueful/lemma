import { useMutation } from '@pinia/colada'
import { useSessionStore } from '@/stores/session'

export interface UploadedAsset {
  url: string
  thumb_url?: string | null
  mime_type?: string
  [k: string]: unknown
}

// Blob upload lives OUTSIDE the /v1/admin typed surface (POST /api/v1/blobs) and is multipart,
// so it uses raw fetch with the session bearer rather than the typed openapi-fetch client.
export async function uploadBlob(
  file: File,
  opts: { visibility?: 'public' | 'private'; pathPrefix?: string } = {},
): Promise<UploadedAsset> {
  const token = useSessionStore().accessToken
  const form = new FormData()
  form.append('file', file)
  if (opts.visibility) form.append('visibility', opts.visibility)
  if (opts.pathPrefix) form.append('path_prefix', opts.pathPrefix)

  const res = await fetch('/api/v1/blobs', {
    method: 'POST',
    credentials: 'include',
    headers: token ? { authorization: `Bearer ${token}` } : {},
    body: form,
  })
  if (!res.ok) throw new Error(`upload failed (${res.status})`)
  const json = await res.json()
  return (json?.data ?? {}) as UploadedAsset
}

export function useUploadMedia() {
  return useMutation({
    mutation: (vars: { file: File; visibility?: 'public' | 'private' }) =>
      uploadBlob(vars.file, { visibility: vars.visibility }),
  })
}
