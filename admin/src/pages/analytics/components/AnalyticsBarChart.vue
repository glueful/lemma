<script setup lang="ts">
import { computed } from 'vue'
import { VisXYContainer, VisStackedBar, VisAxis } from '@unovis/vue'

interface BarItem {
  subject: string
  count: number
}
const props = defineProps<{ items: BarItem[]; color?: string; height?: number }>()

const isEmpty = computed(() => props.items.length === 0)
const x = (_d: BarItem, i: number) => i
const y = (d: BarItem) => d.count
const xTickFormat = (i: number) => props.items[i]?.subject ?? ''
</script>

<template>
  <div v-if="isEmpty" data-test="analytics-bar-empty" class="p-6 text-center text-sm text-muted">
    No activity in this range yet
  </div>
  <div v-else data-test="analytics-bar-chart" :style="{ height: `${height ?? 240}px` }">
    <VisXYContainer :data="items" :height="height ?? 240">
      <VisStackedBar :x="x" :y="y" :color="color ?? 'var(--ui-primary)'" />
      <VisAxis type="x" :tick-format="xTickFormat" :grid-line="false" />
      <VisAxis type="y" :grid-line="true" />
    </VisXYContainer>
  </div>
</template>
