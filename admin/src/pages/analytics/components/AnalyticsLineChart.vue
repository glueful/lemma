<script lang="ts">
// A plain <script> block (NOT <script setup>, which forbids `export`) so the page can import this
// type: `import AnalyticsLineChart, { type LineSeries } from '.../AnalyticsLineChart.vue'`.
export interface LineSeries {
  key: string
  label: string
  color: string
  points: { day: string; count: number }[]
}
</script>

<script setup lang="ts">
import { computed } from 'vue'
import { VisXYContainer, VisLine, VisAxis, VisTooltip } from '@unovis/vue'

const props = defineProps<{ series: LineSeries[]; height?: number }>()

// Merge every series into one record per day: { day, <seriesKey>: count, ... }. unovis plots each
// VisLine against a shared x index; y reads that series' key off the row.
interface Row {
  day: string
  [key: string]: number | string
}

const rows = computed<Row[]>(() => {
  const byDay = new Map<string, Row>()
  for (const s of props.series) {
    for (const p of s.points) {
      const row = byDay.get(p.day) ?? { day: p.day }
      row[s.key] = p.count
      byDay.set(p.day, row)
    }
  }
  return [...byDay.values()].sort((a, b) => String(a.day).localeCompare(String(b.day)))
})

const x = (_row: Row, i: number) => i
const xTickFormat = (i: number) => rows.value[i]?.day?.slice(5) ?? '' // MM-DD
</script>

<template>
  <div data-test="analytics-line-chart" :style="{ height: `${height ?? 240}px` }">
    <VisXYContainer :data="rows" :height="height ?? 240">
      <VisLine
        v-for="s in series"
        :key="s.key"
        :x="x"
        :y="(row: Row) => Number(row[s.key] ?? 0)"
        :color="s.color"
      />
      <VisAxis type="x" :tick-format="xTickFormat" :grid-line="false" />
      <VisAxis type="y" :grid-line="true" />
      <VisTooltip />
    </VisXYContainer>
  </div>
</template>
