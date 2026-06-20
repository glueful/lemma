<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { runtimeConfig } from '@/runtime/config'

definePage({ meta: { layout: 'auth' } })

const router = useRouter()

const schema = z.object({
  site_name: z.string().min(1, 'Site name is required.'),
  admin_email: z.email('Enter a valid email.'),
  admin_password: z.string().min(8, 'Use at least 8 characters.'),
  locale: z.string().min(2),
})
type Schema = z.output<typeof schema>

// Phase 1 is en-only in the UI; locale is seeded from runtime config, not prompted.
const state = reactive({
  site_name: '',
  admin_email: '',
  admin_password: '',
  locale: runtimeConfig.defaultLocale,
})
const error = ref<string | null>(null)
const loading = ref(false)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  error.value = null
  loading.value = true
  try {
    // /admin/setup is UNAUTHENTICATED and OUTSIDE the /v1/admin client surface, so use raw fetch.
    const res = await fetch('/admin/setup', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(event.data),
    })
    if (res.status === 409) {
      error.value = 'This instance has already been set up.'
      return
    }
    if (!res.ok) {
      error.value = 'Setup failed. Check your details and try again.'
      return
    }
    await router.push('/login')
  } catch {
    error.value = 'Setup failed. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UForm :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
    <h1 class="text-lg font-semibold text-highlighted">Welcome to Lemma</h1>
    <p class="text-sm text-muted">Create your first admin to get started.</p>

    <UFormField label="Site name" name="site_name">
      <UInput v-model="state.site_name" class="w-full" />
    </UFormField>

    <UFormField label="Admin email" name="admin_email">
      <UInput v-model="state.admin_email" type="email" autocomplete="email" class="w-full" />
    </UFormField>

    <UFormField label="Admin password" name="admin_password">
      <UInput
        v-model="state.admin_password"
        type="password"
        autocomplete="new-password"
        class="w-full"
      />
    </UFormField>

    <UAlert v-if="error" color="error" variant="soft" :title="error" />

    <UButton type="submit" block :loading="loading">Create admin</UButton>
  </UForm>
</template>
