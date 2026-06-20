<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'
import { useSessionStore } from '@/stores/session'

definePage({ meta: { layout: 'auth' } })

const router = useRouter()
const route = useRoute()
const session = useSessionStore()

const schema = z.object({
  email: z.email('Enter a valid email.'),
  password: z.string().min(1, 'Password is required.'),
})
type Schema = z.output<typeof schema>

const state = reactive({ email: '', password: '' })
const error = ref<string | null>(null)
const loading = ref(false)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  error.value = null
  loading.value = true
  try {
    await session.login(event.data.email, event.data.password)
    // Honour ?redirect= from the auth guard; default to Home.
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.push(redirect)
  } catch {
    error.value = 'Invalid email or password.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UForm :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
    <h1 class="text-lg font-semibold text-highlighted">Sign in</h1>

    <UFormField label="Email" name="email">
      <UInput v-model="state.email" type="email" autocomplete="email" class="w-full" />
    </UFormField>

    <UFormField label="Password" name="password">
      <UInput
        v-model="state.password"
        type="password"
        autocomplete="current-password"
        class="w-full"
      />
    </UFormField>

    <UAlert v-if="error" color="error" variant="soft" :title="error" />

    <UButton type="submit" block :loading="loading">Sign in</UButton>
  </UForm>
</template>
