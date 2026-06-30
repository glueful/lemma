<script setup lang="ts">
import { ref, computed, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    open: boolean
    title: string
    /** The exact token (collection or field name) the user must type to confirm a destructive drop. */
    confirmName: string
    /** false = empty-table light path: no typed confirmation needed (the backend waives it too). */
    requireConfirm?: boolean
    loading?: boolean
    message?: string
  }>(),
  { requireConfirm: true, loading: false },
)

const emit = defineEmits<{ confirm: [token: string | undefined]; cancel: [] }>()

const typed = ref('')
watch(
  () => props.open,
  (open) => {
    if (!open) typed.value = ''
  },
)

const canConfirm = computed(() => !props.requireConfirm || typed.value === props.confirmName)

function onConfirm() {
  if (!canConfirm.value) return
  emit('confirm', props.requireConfirm ? typed.value : undefined)
}
</script>

<template>
  <UModal
    :open="open"
    :title="title"
    @update:open="
      (v: boolean) => {
        if (!v) emit('cancel')
      }
    "
  >
    <template #body>
      <p class="text-sm text-muted">
        {{ message ?? `This permanently drops “${confirmName}” and all of its data.` }}
      </p>
      <div v-if="requireConfirm" class="mt-3 space-y-1">
        <p class="text-xs text-muted">
          Type <code class="text-default">{{ confirmName }}</code> to confirm.
        </p>
        <UInput v-model="typed" data-test="drop-confirm-input" :placeholder="confirmName" />
      </div>
    </template>

    <template #footer>
      <div class="flex justify-end gap-2 w-full">
        <UButton
          color="neutral"
          variant="ghost"
          label="Cancel"
          :disabled="loading"
          @click="emit('cancel')"
        />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Drop"
          data-test="drop-confirm-button"
          :disabled="!canConfirm || loading"
          :loading="loading"
          @click="onConfirm"
        />
      </div>
    </template>
  </UModal>
</template>
