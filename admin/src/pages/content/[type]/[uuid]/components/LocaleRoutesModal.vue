<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useQueryCache } from '@pinia/colada'
import { useRoutes, saveRoute, deleteRoute } from '@/queries/routes'
import { qk } from '@/queries/keys'
import type { Locale } from '@/queries/locales'
import { useNotify } from '@/composables/useNotify'

const props = defineProps<{ uuid: string; enabled: Locale[] }>()
const open = defineModel<boolean>('open', { required: true })

const { success, error: notifyError } = useNotify()
const cache = useQueryCache()
const { data: routes } = useRoutes(() => props.uuid)

// Editable slug per locale, seeded from the loaded routes.
const slugs = reactive<Record<string, string>>({})
const busy = ref('') // locale currently saving/deleting
watch(
  routes,
  (r) => {
    for (const l of props.enabled) {
      slugs[l.code] = r?.find((x) => x.locale === l.code)?.slug ?? ''
    }
  },
  { immediate: true },
)

const invalidate = () => cache.invalidateQueries({ key: qk.routes(props.uuid) })

async function onSave(code: string) {
  busy.value = code
  try {
    await saveRoute(props.uuid, code, slugs[code] ?? '')
    await invalidate()
    success(`Route saved for ${code}`)
  } catch (e) {
    notifyError(e, 'Couldn’t save route')
  } finally {
    busy.value = ''
  }
}
async function onDelete(code: string) {
  busy.value = code
  try {
    await deleteRoute(props.uuid, code)
    slugs[code] = ''
    await invalidate()
    success(`Route removed for ${code}`)
  } catch (e) {
    notifyError(e, 'Couldn’t remove route')
  } finally {
    busy.value = ''
  }
}
</script>

<template>
  <UModal v-model:open="open" title="Routes by locale">
    <template #body>
      <div class="space-y-3">
        <div v-for="l in enabled" :key="l.code" class="flex items-end gap-2">
          <UFormField :label="`${l.name} (${l.code})`" class="flex-1">
            <UInput v-model="slugs[l.code]" placeholder="my-page" class="w-full" />
          </UFormField>
          <UButton variant="subtle" :loading="busy === l.code" @click="onSave(l.code)"
            >Save</UButton
          >
          <UButton
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            :loading="busy === l.code"
            :aria-label="`Remove ${l.code} route`"
            @click="onDelete(l.code)"
          />
        </div>
      </div>
    </template>
  </UModal>
</template>
