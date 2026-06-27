<script setup lang="ts">
import { computed, ref, useTemplateRef, watchEffect } from 'vue'
import { useIntervalFn } from '@vueuse/core'
import {
  useAdapters,
  useJobs,
  useJobErrors,
  useImportExportMutations,
  uploadImportFile,
  downloadExport,
  isJobActive,
  type IeJob,
} from '@/queries/importExport'
import { useContentTypes } from '@/queries/contentTypes'
import { runtimeConfig } from '@/runtime/config'
import { useNotify } from '@/composables/useNotify'

definePage({ meta: { requiresAuth: true } })

const { success, error: notifyError } = useNotify()

const { data: adapters } = useAdapters()
const { data: jobs, status: jobsStatus, refresh, isLoading } = useJobs()
const { runExport, runImport, cancel, retry } = useImportExportMutations()

// Poll while any job is still progressing (Pinia Colada has no refetchInterval).
const hasActive = computed(() => (jobs.value ?? []).some((j) => isJobActive(j.status)))
useIntervalFn(() => {
  if (hasActive.value) refresh()
}, 4000)

// ── Export ──────────────────────────────────────────────────────────────────
const exportAdapter = ref('')
const exporterItems = computed(() =>
  (adapters.value?.exporters ?? []).map((a) => ({ label: a.label, value: a.key })),
)
watchEffect(() => {
  if (!exportAdapter.value && exporterItems.value.length) {
    exportAdapter.value = exporterItems.value[0]!.value
  }
})

async function onExport() {
  if (!exportAdapter.value) return
  try {
    await runExport.mutateAsync({ adapter: exportAdapter.value })
    success('Export queued', 'It will appear in the jobs list below as it runs.')
  } catch (e) {
    notifyError(e, 'Could not start the export')
  }
}

// ── Import ──────────────────────────────────────────────────────────────────
const importAdapter = ref('')
const importerItems = computed(() =>
  (adapters.value?.importers ?? []).map((a) => ({ label: a.label, value: a.key })),
)
watchEffect(() => {
  if (!importAdapter.value && importerItems.value.length) {
    importAdapter.value = importerItems.value[0]!.value
  }
})

const importMode = ref<'dry_run' | 'commit'>('dry_run')
const modeItems = [
  { label: 'Dry run (preview, no writes)', value: 'dry_run' },
  { label: 'Commit (write to the database)', value: 'commit' },
]

const fileInput = useTemplateRef<HTMLInputElement>('fileInput')
const selectedFile = ref<File | null>(null)
const importing = ref(false)

// ── CSV mapping wizard ────────────────────────────────────────────────────────
// The CSV adapter needs an options bag (target type + field→column mapping); other adapters just
// take a file. When the CSV adapter is chosen we collect that here and pass it as `options`.
const SKIP = '__skip__'
const isCsv = computed(() => importAdapter.value === 'csv.content')

const { data: contentTypes } = useContentTypes()
const typeItems = computed(() =>
  (contentTypes.value ?? []).map((t) => ({ label: t.name ?? t.slug, value: t.slug })),
)
const csvType = ref('')
const csvFields = computed(() =>
  (contentTypes.value?.find((t) => t.slug === csvType.value)?.schema ?? []).map((f) => ({
    name: String(f.name ?? ''),
    required: !!f.required,
  })),
)
const csvPublish = ref(false)

const csvColumns = ref<string[]>([])
// field name → CSV column header (or SKIP)
const csvMapping = ref<Record<string, string>>({})
const columnItems = computed(() => [
  { label: '— skip —', value: SKIP },
  ...csvColumns.value.map((c) => ({ label: c, value: c })),
])

function parseCsvHeader(text: string): string[] {
  const firstLine = text.split(/\r?\n/).find((l) => l.trim() !== '') ?? ''
  // Minimal header parse (headers rarely contain quoted commas); strips surrounding quotes.
  return firstLine.split(',').map((c) => c.trim().replace(/^"(.*)"$/, '$1'))
}

// When the header or the chosen type changes, default each field to a same-name column else skip.
watchEffect(() => {
  const cols = csvColumns.value
  const next: Record<string, string> = {}
  for (const f of csvFields.value) {
    const name = String(f.name)
    const match = cols.find((c) => c.toLowerCase() === name.toLowerCase())
    next[name] = match ?? csvMapping.value[name] ?? SKIP
  }
  csvMapping.value = next
})

// Required fields must be mapped to a real column before CSV import can run.
const csvReady = computed(
  () =>
    !isCsv.value ||
    (csvType.value !== '' &&
      csvColumns.value.length > 0 &&
      csvFields.value.every(
        (f) => !f.required || (csvMapping.value[String(f.name)] ?? SKIP) !== SKIP,
      )),
)

async function onFilePicked(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0] ?? null
  selectedFile.value = file
  csvColumns.value = file && isCsv.value ? parseCsvHeader(await file.text()) : []
}

function csvOptions(): Record<string, unknown> {
  const mapping: Record<string, string> = {}
  for (const [field, column] of Object.entries(csvMapping.value)) {
    if (column !== SKIP) mapping[field] = column
  }
  return {
    content_type: csvType.value,
    mapping,
    locale: runtimeConfig.defaultLocale,
    publish: csvPublish.value,
  }
}

async function onImport() {
  const file = selectedFile.value
  if (!file || !importAdapter.value || !csvReady.value) return
  importing.value = true
  try {
    const uploaded = await uploadImportFile(file)
    await runImport.mutateAsync({
      adapter: importAdapter.value,
      disk: uploaded.disk,
      path: uploaded.path,
      mode: importMode.value,
      options: isCsv.value ? csvOptions() : undefined,
    })
    success(
      importMode.value === 'dry_run' ? 'Dry run queued' : 'Import queued',
      'It will appear in the jobs list below as it runs.',
    )
    selectedFile.value = null
    csvColumns.value = []
    if (fileInput.value) fileInput.value.value = ''
  } catch (e) {
    notifyError(e, 'Could not start the import')
  } finally {
    importing.value = false
  }
}

// ── Jobs ──────────────────────────────────────────────────────────────────────
function statusColor(s: string): 'success' | 'error' | 'neutral' | 'info' {
  if (s === 'completed') return 'success'
  if (s === 'failed') return 'error'
  if (s === 'cancelled') return 'neutral'
  return 'info'
}

const acting = ref('') // uuid currently being cancelled/retried/downloaded

async function onDownload(job: IeJob) {
  acting.value = job.uuid
  try {
    await downloadExport(job.uuid)
  } catch (e) {
    notifyError(e, 'Could not download the export')
  } finally {
    acting.value = ''
  }
}

async function onCancel(job: IeJob) {
  acting.value = job.uuid
  try {
    await cancel.mutateAsync(job.uuid)
    success('Job cancelled')
  } catch (e) {
    notifyError(e, 'Could not cancel the job')
  } finally {
    acting.value = ''
  }
}

async function onRetry(job: IeJob) {
  acting.value = job.uuid
  try {
    await retry.mutateAsync(job.uuid)
    success('Job re-queued')
  } catch (e) {
    notifyError(e, 'Could not retry the job')
  } finally {
    acting.value = ''
  }
}

// ── Errors modal ───────────────────────────────────────────────────────────────
const errorsJob = ref<IeJob | null>(null)
const errorsUuid = computed(() => errorsJob.value?.uuid ?? '')
const { data: jobErrors, status: errorsStatus } = useJobErrors(errorsUuid)

function fmtTime(v?: string | null): string {
  if (!v) return '—'
  const d = new Date(v)
  return Number.isNaN(d.getTime())
    ? '—'
    : d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
}
</script>

<template>
  <UDashboardPanel id="settings-import-export">
    <template #header>
      <UDashboardNavbar title="Import / Export">
        <template #right>
          <UButton
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="isLoading"
            @click="refresh()"
          >
            Refresh
          </UButton>
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <div class="mx-auto w-full max-w-4xl space-y-6">
        <p class="text-sm text-muted">
          Move content in and out as NDJSON. Jobs run in the background — they progress only while a
          queue worker is running.
        </p>

        <div class="grid gap-6 md:grid-cols-2">
          <!-- Export -->
          <UCard>
            <template #header><h2 class="font-semibold text-default">Export</h2></template>
            <div class="space-y-4">
              <UFormField label="What to export">
                <USelect v-model="exportAdapter" :items="exporterItems" class="w-full" />
              </UFormField>
              <p class="text-xs text-muted">Format: NDJSON</p>
              <UButton
                icon="i-lucide-download"
                :loading="runExport.isLoading.value"
                :disabled="!exportAdapter"
                @click="onExport"
              >
                Start export
              </UButton>
            </div>
          </UCard>

          <!-- Import -->
          <UCard>
            <template #header><h2 class="font-semibold text-default">Import</h2></template>
            <div class="space-y-4">
              <UFormField label="Adapter">
                <USelect v-model="importAdapter" :items="importerItems" class="w-full" />
              </UFormField>

              <UFormField
                v-if="isCsv"
                label="Content type"
                hint="Each CSV row becomes an entry of this type"
              >
                <USelect
                  v-model="csvType"
                  :items="typeItems"
                  placeholder="Choose a content type"
                  class="w-full"
                />
              </UFormField>

              <UFormField
                label="File"
                :hint="isCsv ? 'CSV with a header row' : 'NDJSON exported from Lemma'"
              >
                <div class="flex items-center gap-2">
                  <UButton
                    icon="i-lucide-paperclip"
                    color="neutral"
                    variant="subtle"
                    label="Choose file…"
                    @click="fileInput?.click()"
                  />
                  <span class="truncate text-sm text-muted">
                    {{ selectedFile?.name ?? 'No file' }}
                  </span>
                </div>
              </UFormField>

              <UFormField
                v-if="isCsv && csvType && csvColumns.length"
                label="Map fields to columns"
                hint="Required fields (*) must be mapped"
              >
                <div class="space-y-2">
                  <div v-for="f in csvFields" :key="f.name" class="flex items-center gap-2">
                    <span class="w-28 shrink-0 truncate text-sm text-default">
                      {{ f.name }}<span v-if="f.required" class="text-error">*</span>
                    </span>
                    <USelect v-model="csvMapping[f.name]" :items="columnItems" class="flex-1" />
                  </div>
                </div>
              </UFormField>

              <UFormField v-if="isCsv" label="On commit">
                <USwitch v-model="csvPublish" label="Publish imported entries" />
              </UFormField>

              <UFormField label="Mode">
                <USelect v-model="importMode" :items="modeItems" class="w-full" />
              </UFormField>

              <UButton
                icon="i-lucide-upload"
                :loading="importing || runImport.isLoading.value"
                :disabled="!selectedFile || !importAdapter || !csvReady"
                @click="onImport"
              >
                {{ importMode === 'dry_run' ? 'Run dry run' : 'Import' }}
              </UButton>
            </div>
          </UCard>
        </div>

        <!-- Jobs -->
        <section class="space-y-3">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-muted">Recent jobs</h2>

          <div v-if="jobsStatus === 'pending'" class="space-y-2">
            <USkeleton class="h-16" />
            <USkeleton class="h-16" />
          </div>

          <UEmpty
            v-else-if="!(jobs ?? []).length"
            icon="i-lucide-arrow-down-up"
            title="No jobs yet"
            description="Start an export or import above."
          />

          <div v-else class="flex flex-col gap-2">
            <div
              v-for="job in jobs"
              :key="job.uuid"
              class="flex items-start justify-between gap-4 rounded-xl border border-default p-4"
            >
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <UBadge
                    :label="job.type"
                    :color="job.type === 'export' ? 'primary' : 'neutral'"
                    variant="subtle"
                    size="sm"
                    class="capitalize"
                  />
                  <UBadge
                    :label="job.status"
                    :color="statusColor(job.status)"
                    variant="subtle"
                    size="sm"
                    class="capitalize"
                  />
                  <span v-if="job.mode === 'dry_run'" class="text-xs text-muted">dry run</span>
                </div>
                <p class="mt-1 text-sm text-default">{{ job.adapter }}</p>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted">
                  <span>{{ job.processed_records }} / {{ job.total_records }} records</span>
                  <span v-if="job.failed_records > 0" class="text-error">
                    {{ job.failed_records }} failed
                  </span>
                  <span>{{ fmtTime(job.created_at) }}</span>
                </div>
              </div>

              <div class="flex shrink-0 items-center gap-1">
                <UButton
                  v-if="job.failed_records > 0 || job.error_overflow_count > 0"
                  label="Errors"
                  color="neutral"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-triangle-alert"
                  @click="errorsJob = job"
                />
                <UButton
                  v-if="job.type === 'export' && job.status === 'completed'"
                  label="Download"
                  color="neutral"
                  variant="subtle"
                  size="xs"
                  icon="i-lucide-download"
                  :loading="acting === job.uuid"
                  @click="onDownload(job)"
                />
                <UButton
                  v-if="job.status === 'failed'"
                  label="Retry"
                  color="neutral"
                  variant="subtle"
                  size="xs"
                  icon="i-lucide-rotate-cw"
                  :loading="acting === job.uuid"
                  @click="onRetry(job)"
                />
                <UButton
                  v-if="isJobActive(job.status)"
                  label="Cancel"
                  color="error"
                  variant="ghost"
                  size="xs"
                  icon="i-lucide-x"
                  :loading="acting === job.uuid"
                  @click="onCancel(job)"
                />
              </div>
            </div>
          </div>
        </section>
      </div>
    </template>
  </UDashboardPanel>

  <input
    ref="fileInput"
    type="file"
    :accept="isCsv ? '.csv' : '.ndjson,.jsonl,.json'"
    class="hidden"
    @change="onFilePicked"
  />

  <UModal
    :open="errorsJob !== null"
    title="Job errors"
    @update:open="
      (v: boolean) => {
        if (!v) errorsJob = null
      }
    "
  >
    <template #body>
      <div v-if="errorsStatus === 'pending'" class="space-y-2">
        <USkeleton class="h-10" />
        <USkeleton class="h-10" />
      </div>
      <UEmpty
        v-else-if="!(jobErrors ?? []).length"
        icon="i-lucide-check"
        title="No recorded errors"
        description="This job has no stored error records."
      />
      <ul v-else class="divide-y divide-default">
        <li v-for="err in jobErrors" :key="err.uuid" class="py-2">
          <div class="flex items-center gap-2">
            <UBadge
              :label="err.severity"
              :color="err.severity === 'error' ? 'error' : 'warning'"
              variant="subtle"
              size="xs"
              class="capitalize"
            />
            <code class="text-xs text-muted">{{ err.code }}</code>
            <span v-if="err.record_number !== null" class="text-xs text-dimmed">
              record {{ err.record_number }}
            </span>
          </div>
          <p class="mt-0.5 break-words text-sm text-default">{{ err.message }}</p>
        </li>
      </ul>
    </template>
  </UModal>
</template>
