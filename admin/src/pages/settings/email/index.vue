<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import {
  useEmailSettings,
  useEmailSettingsMutations,
  type EmailSettingsInput,
} from '@/queries/emailSettings'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()
const { data, status } = useEmailSettings()
const { save, test } = useEmailSettingsMutations()

const mailerOptions = ['smtp', 'sendmail']
const encryptionOptions: { label: string; value: string }[] = [
  { label: 'STARTTLS (TLS)', value: 'tls' },
  { label: 'SSL/TLS', value: 'ssl' },
  { label: 'None', value: '' },
]

const form = reactive<EmailSettingsInput>({
  mailer: 'smtp',
  host: '',
  port: '',
  username: '',
  encryption: 'tls',
  from: '',
  from_name: '',
  bcc: '',
  logo_url: '',
  password: '',
})

// The password is never returned — show whether one is already set so the field can stay blank.
const passwordSet = computed(() => data.value?.password_set ?? false)

watch(
  data,
  (s) => {
    if (!s) return
    Object.assign(form, {
      mailer: s.mailer || 'smtp',
      host: s.host ?? '',
      port: s.port ?? '',
      username: s.username ?? '',
      encryption: s.encryption ?? '',
      from: s.from ?? '',
      from_name: s.from_name ?? '',
      bcc: s.bcc ?? '',
      logo_url: s.logo_url ?? '',
      password: '',
    })
  },
  { immediate: true },
)

async function onSave() {
  try {
    // password: '' tells the backend to keep the existing one.
    await save.mutateAsync({ ...form })
    form.password = ''
    success('Email settings saved', 'Changes apply on the next request (a restart may be needed).')
  } catch (e) {
    notifyError(e, 'Couldn’t save email settings')
  }
}

const testTo = ref('')
async function onTest() {
  if (testTo.value === '') return
  try {
    await test.mutateAsync(testTo.value)
    success('Test email sent', `Sent to ${testTo.value}.`)
  } catch (e) {
    notifyError(e, 'Test email failed')
  }
}
</script>

<template>
  <UDashboardPanel id="settings-email">
    <template #header>
      <UDashboardNavbar title="Email">
        <template #right>
          <UButton icon="i-lucide-save" :loading="save.isLoading.value" @click="onSave">
            Save
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-2xl space-y-6">
        <div v-if="status === 'pending'" class="space-y-3">
          <USkeleton class="h-48" />
          <USkeleton class="h-32" />
        </div>

        <template v-else>
          <UCard>
            <template #header><h2 class="font-semibold text-default">Mailer</h2></template>
            <div class="space-y-4">
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField label="Mailer">
                  <USelect v-model="form.mailer" :items="mailerOptions" class="w-full" />
                </UFormField>
                <UFormField label="Encryption">
                  <USelect v-model="form.encryption" :items="encryptionOptions" class="w-full" />
                </UFormField>
              </div>

              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField label="Host">
                  <UInput v-model="form.host" placeholder="smtp.example.com" class="w-full" />
                </UFormField>
                <UFormField label="Port">
                  <UInput
                    v-model="form.port"
                    inputmode="numeric"
                    placeholder="587"
                    class="w-full"
                  />
                </UFormField>
              </div>

              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField label="Username">
                  <UInput v-model="form.username" autocomplete="off" class="w-full" />
                </UFormField>
                <UFormField label="Password" :hint="passwordSet ? 'A password is set' : undefined">
                  <UInput
                    v-model="form.password"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="passwordSet ? '•••••••• (unchanged)' : ''"
                    class="w-full"
                  />
                </UFormField>
              </div>
            </div>
          </UCard>

          <UCard>
            <template #header><h2 class="font-semibold text-default">Sender</h2></template>
            <div class="space-y-4">
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField label="From address">
                  <UInput
                    v-model="form.from"
                    type="email"
                    placeholder="no-reply@example.com"
                    class="w-full"
                  />
                </UFormField>
                <UFormField label="From name">
                  <UInput v-model="form.from_name" placeholder="Lemma" class="w-full" />
                </UFormField>
              </div>
              <div class="grid gap-4 sm:grid-cols-2">
                <UFormField label="BCC" hint="Optional">
                  <UInput v-model="form.bcc" type="email" class="w-full" />
                </UFormField>
                <UFormField label="Logo URL" hint="Optional">
                  <UInput v-model="form.logo_url" class="w-full" />
                </UFormField>
              </div>
            </div>
          </UCard>

          <UCard>
            <template #header
              ><h2 class="font-semibold text-default">Send a test email</h2></template
            >
            <div class="flex flex-wrap items-end gap-2">
              <UFormField label="Recipient" class="flex-1">
                <UInput
                  v-model="testTo"
                  type="email"
                  placeholder="you@example.com"
                  class="w-full"
                />
              </UFormField>
              <UButton
                color="neutral"
                variant="subtle"
                icon="i-lucide-send"
                :loading="test.isLoading.value"
                :disabled="testTo === ''"
                @click="onTest"
              >
                Send test
              </UButton>
            </div>
            <p class="mt-2 text-xs text-muted">
              Uses the saved settings — save first, then test to verify SMTP works.
            </p>
          </UCard>
        </template>
      </div>
    </template>
  </UDashboardPanel>
</template>
