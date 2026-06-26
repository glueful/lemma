<script setup lang="ts">
import { reactive, ref, useTemplateRef } from 'vue'
import { useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { forgotPassword } from '@/api/auth'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { layout: 'auth' } })

const router = useRouter()

const schema = z.object({
  email: z.email('Enter a valid email.'),
})
type Schema = z.output<typeof schema>

const state = reactive({ email: '' })
const loading = ref(false)
const { success: notifySuccess, error: notifyError } = useNotify()
const forgotForm = useTemplateRef<Form<Schema>>('forgotForm')

async function onSubmit(event: FormSubmitEvent<Schema>) {
  loading.value = true
  try {
    await forgotPassword(event.data.email)
    notifySuccess('Check your email', 'We sent a verification code to reset your password.')
    // Carry the email forward so the OTP step knows whose code to verify.
    await router.push({ path: '/verify-otp', query: { email: event.data.email } })
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) forgotForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Could not send reset code')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <UForm ref="forgotForm" :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
    <h1 class="text-lg font-semibold text-highlighted">Forgot password</h1>
    <p class="text-sm text-muted">
      Enter your email and we'll send you a code to reset your password.
    </p>

    <UFormField label="Email" name="email">
      <UInput v-model="state.email" type="email" class="w-full" :ui="{ base: 'bg-white/35' }" />
    </UFormField>

    <UButton type="submit" block :loading="loading">Send reset code</UButton>

    <div class="text-center">
      <ULink to="/login" class="text-sm text-muted hover:text-default transition-colors">
        Back to sign in
      </ULink>
    </div>
  </UForm>
</template>
