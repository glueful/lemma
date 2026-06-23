<script setup lang="ts">
import { computed, onMounted, reactive, ref, useTemplateRef } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import * as z from 'zod'
import type { Form, FormSubmitEvent } from '@nuxt/ui'
import { verifyOtp, resendOtp } from '@/api/auth'
import { toApiError } from '@/api/errors'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { layout: 'auth' } })

const router = useRouter()
const route = useRoute()
const { success: notifySuccess, error: notifyError } = useNotify()

// The email is carried in the query from the forgot-password step. Without it there's nothing to
// verify against, so send the user back to request a code.
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))
onMounted(() => {
  if (email.value === '') router.replace('/forgot-password')
})

const schema = z.object({
  otp: z.string().min(4, 'Enter the code from your email.'),
})
type Schema = z.output<typeof schema>

const state = reactive({ otp: '' })
const loading = ref(false)
const resending = ref(false)
const otpForm = useTemplateRef<Form<Schema>>('otpForm')

async function onSubmit(event: FormSubmitEvent<Schema>) {
  if (email.value === '') {
    await router.replace('/forgot-password')
    return
  }
  loading.value = true
  try {
    const result = await verifyOtp(email.value, event.data.otp)
    const token = result.data?.reset_token
    if (token === undefined || token === '') {
      throw new Error('No reset token was returned. Please request a new code.')
    }
    // Hand the single-use reset token to the final step.
    await router.push({ path: '/reset-password', query: { token } })
  } catch (e) {
    const err = toApiError(e)
    const fieldErrors = Object.entries(err.fieldErrors).map(([name, message]) => ({
      name,
      message,
    }))
    if (fieldErrors.length > 0) otpForm.value?.setErrors(fieldErrors)
    notifyError(err, 'Verification failed')
  } finally {
    loading.value = false
  }
}

async function onResend() {
  if (email.value === '') {
    await router.replace('/forgot-password')
    return
  }
  resending.value = true
  try {
    await resendOtp(email.value)
    notifySuccess('Code sent', 'We sent a new verification code to your email.')
  } catch (e) {
    notifyError(e, 'Could not resend code')
  } finally {
    resending.value = false
  }
}
</script>

<template>
  <UForm ref="otpForm" :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
    <h1 class="text-lg font-semibold text-highlighted">Enter verification code</h1>
    <p class="text-sm text-muted">
      <template v-if="email">
        We sent a code to <span class="text-default">{{ email }}</span
        >.
      </template>
      <template v-else>Enter the code we emailed you.</template>
    </p>

    <UFormField label="Verification code" name="otp">
      <UInput
        v-model="state.otp"
        inputmode="numeric"
        autocomplete="one-time-code"
        class="w-full"
        :ui="{ base: 'bg-white/35' }"
        placeholder="123456"
      />
    </UFormField>

    <UButton type="submit" block :loading="loading">Verify code</UButton>

    <div class="flex items-center justify-between">
      <ULink to="/forgot-password" class="text-sm text-muted hover:text-default transition-colors">
        Use a different email
      </ULink>
      <UButton variant="link" color="neutral" size="sm" :loading="resending" @click="onResend">
        Resend code
      </UButton>
    </div>
  </UForm>
</template>
