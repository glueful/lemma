<script setup lang="ts">
// Reusable date + time picker: UInputDate (date) + UInputTime (time) bridged to a single ISO-8601
// string. The model is the backend's canonical datetime form — `YYYY-MM-DDTHH:mm:ssZ` (UTC).
//
// Values are treated as UTC wall-clock (what you see is what's stored), so there is no timezone
// conversion and the round-trip through the backend's `gmdate(... 'Z')` normalization is exact.
import { computed } from 'vue'
import { CalendarDate, Time, parseDate, type DateValue } from '@internationalized/date'

const model = defineModel<string>()

function pad(n: number): string {
  return String(n).padStart(2, '0')
}

// Split the stored ISO string into its date + time parts (either may be absent).
function parsed(): { date?: DateValue; time?: Time } {
  const v = model.value
  if (v === undefined || v === '') return {}
  let date: DateValue | undefined
  try {
    date = parseDate(v.slice(0, 10))
  } catch {
    date = undefined
  }
  const m = v.match(/T(\d{2}):(\d{2})/)
  const time = m ? new Time(Number(m[1]), Number(m[2])) : undefined
  return { date, time }
}

function commit(date: DateValue | undefined, time: Time | undefined): void {
  if (!date) {
    model.value = undefined
    return
  }
  const t = time ?? new Time(0, 0)
  model.value = `${date.toString()}T${pad(t.hour)}:${pad(t.minute)}:00Z`
}

function todayDate(): DateValue {
  const d = new Date()
  return new CalendarDate(d.getFullYear(), d.getMonth() + 1, d.getDate())
}

const dateValue = computed<DateValue | undefined>({
  get: () => parsed().date,
  set: (d) => commit(d, parsed().time),
})

// Setting a time before a date defaults the date to today, so the value is always a valid datetime.
const timeValue = computed<Time | undefined>({
  get: () => parsed().time,
  set: (t) => commit(parsed().date ?? todayDate(), t),
})
</script>

<template>
  <div class="flex flex-wrap items-center gap-2">
    <UInputDate v-model="dateValue" />
    <UInputTime v-model="timeValue" />
  </div>
</template>
