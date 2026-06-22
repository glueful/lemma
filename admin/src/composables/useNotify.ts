import { useToast } from '@nuxt/ui/composables/useToast'
import { ApiError, toApiError } from '@/api/errors'

// Consistent success/error toasts for the whole app. `error()` takes ANY thrown value — an
// ApiError from the query layer, an openapi-fetch body, a plain Error, or undefined — and surfaces
// the backend's message as the toast description, with a friendly action title supplied by the
// caller (e.g. "Couldn't save draft").
export function useNotify() {
  const toast = useToast()

  function success(title: string, description?: string) {
    toast.add({ title, description, color: 'success', icon: 'i-lucide-circle-check' })
  }

  function warning(title: string, description?: string) {
    toast.add({ title, description, color: 'warning', icon: 'i-lucide-triangle-alert' })
  }

  function error(err: unknown, title = 'Something went wrong') {
    const apiErr = err instanceof ApiError ? err : toApiError(err)
    toast.add({
      title,
      // Don't echo the title back as the description when there's no specific server message.
      description: apiErr.message === title ? undefined : apiErr.message,
      color: 'error',
      icon: 'i-lucide-circle-alert',
    })
  }

  return { success, warning, error }
}
