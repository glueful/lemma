<script setup lang="ts">
import { ref, watch } from 'vue'
import { useToast } from '@nuxt/ui/composables/useToast'
import type { FieldDef } from '../types'
import { useUploadMedia } from '@/queries/media'

defineProps<{ field: FieldDef }>()
// Stores the uploaded asset reference (its URL). Upload-and-use: pick a file -> upload -> the
// returned URL becomes the field value.
const model = defineModel<string>()
const file = ref<File | null>(null)
const upload = useUploadMedia()
const toast = useToast()

watch(file, async (f) => {
  if (!f) return
  try {
    const asset = await upload.mutateAsync({ file: f })
    model.value = asset.url
  } catch {
    toast.add({ title: 'Upload failed', color: 'error' })
  } finally {
    file.value = null
  }
})
</script>

<template>
  <UFormField :label="field.name" :required="field.required" :name="field.name">
    <UFileUpload v-model="file" />
    <p v-if="upload.isLoading.value" class="mt-1 text-xs text-muted">Uploading…</p>
    <p v-else-if="model" class="mt-1 text-xs text-muted">Current: {{ model }}</p>
  </UFormField>
</template>
