<script setup lang="ts">
// A live preview of the authoring form an entry of this content type would render. Driven by the
// field builder so editors see the shape of what they're defining; controls have no v-model, so any
// input is throwaway (never read or saved).
import type { ContentTypeField } from '@/queries/contentTypes'
import RichText from '@/components/RichText.vue'
import DateTimePicker from '@/components/DateTimePicker.vue'

defineProps<{
  name?: string
  fields: ContentTypeField[]
}>()
</script>

<template>
  <UCard :ui="{ root: 'bg-elevated/40' }">
    <template #header>
      <div class="flex items-center justify-between gap-2">
        <h2 class="font-semibold text-default truncate">{{ name || 'Untitled' }}</h2>
        <UBadge color="neutral" variant="subtle" size="sm">Preview</UBadge>
      </div>
    </template>

    <UEmpty
      v-if="fields.length === 0"
      icon="i-lucide-eye"
      title="Nothing to preview yet"
      description="Add fields to see how the entry form will look."
    />

    <!-- Interactive playground mirroring the real entry form. The controls have no v-model, so any
         input here is throwaway (never read or saved) — it just lets editors feel out the form. -->
    <div v-else class="space-y-4">
      <div v-for="(field, index) in fields" :key="index" class="space-y-1.5">
        <div class="flex flex-wrap items-center gap-1.5">
          <span class="text-sm font-medium text-default">
            {{ field.name || 'Untitled field' }}
          </span>
          <span v-if="field.required" class="text-error" aria-hidden="true">*</span>
          <UBadge color="neutral" variant="subtle" size="sm">{{ field.type }}</UBadge>
          <UBadge v-if="field.localized" color="neutral" variant="outline" size="sm">
            localized
          </UBadge>
          <UBadge v-if="field.filterable" color="neutral" variant="outline" size="sm">
            filterable
          </UBadge>
          <UBadge v-if="field.multiple" color="neutral" variant="outline" size="sm">
            multiple{{ field.max_items ? ` · max ${field.max_items}` : '' }}
          </UBadge>
        </div>

        <!-- rich text uses the same reusable editor the real entry form renders -->
        <RichText
          v-if="field.type === 'text' && field.format === 'rich'"
          placeholder="Rich text…"
        />
        <UTextarea
          v-else-if="field.type === 'text' || field.type === 'json'"
          :rows="field.type === 'json' ? 3 : 2"
          :class="['w-full', field.type === 'json' && 'font-mono']"
          :placeholder="field.type === 'json' ? '{ }' : 'Long text'"
        />
        <USwitch v-else-if="field.type === 'boolean'" />
        <USelect
          v-else-if="field.type === 'enum'"
          class="w-full"
          :items="(field.enum?.length ?? 0) > 0 ? field.enum : ['—']"
          :placeholder="field.enum?.[0] ?? 'Select…'"
        />
        <UFileUpload v-else-if="field.type === 'asset'" />
        <DateTimePicker v-else-if="field.type === 'datetime'" />
        <UInput
          v-else
          class="w-full"
          :type="field.type === 'number' ? 'number' : 'text'"
          :icon="field.type === 'reference' ? 'i-lucide-link' : undefined"
          :placeholder="
            field.type === 'number' ? '0' : field.type === 'reference' ? 'Referenced entry' : 'Text'
          "
        />
      </div>
    </div>
  </UCard>
</template>
