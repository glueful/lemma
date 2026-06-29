import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

vi.mock('@/api/authFetch', () => ({
  authFetch: vi.fn().mockResolvedValue({ data: { capabilities: [] } }),
}))
vi.mock('@/runtime/config', () => ({
  runtimeConfig: { apiBase: '/v1/admin', defaultLocale: 'en' },
}))
vi.mock('@/queries/importExport', () => ({
  useAdapters: () => ({ data: ref(null) }),
  useJobs: () => ({
    data: ref([]),
    status: ref('success'),
    refresh: vi.fn(),
    isLoading: ref(false),
  }),
  useJobErrors: (_: unknown) => ({ data: ref([]), status: ref('success') }),
  useImportExportMutations: () => ({
    runExport: { mutateAsync: vi.fn(), isLoading: ref(false) },
    runImport: { mutateAsync: vi.fn(), isLoading: ref(false) },
    cancel: { mutateAsync: vi.fn() },
    retry: { mutateAsync: vi.fn() },
  }),
  uploadImportFile: vi.fn(),
  downloadExport: vi.fn(),
  isJobActive: vi.fn(() => false),
}))
vi.mock('@/queries/contentTypes', () => ({
  useContentTypes: () => ({ data: ref([]) }),
}))
vi.mock('@vueuse/core', async () => {
  const actual = await vi.importActual<typeof import('@vueuse/core')>('@vueuse/core')
  return { ...actual, useIntervalFn: vi.fn() }
})
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import { useCapabilitiesStore } from '@/stores/capabilities'
import ImportExportPage from '@/pages/settings/import-export/index.vue'

/** Minimal stubs for Nuxt UI and dashboard components used by the page. */
const globalStubs = {
  UDashboardPanel: { template: '<div><slot name="header" /><slot name="body" /></div>' },
  UDashboardNavbar: { template: '<div />' },
  UButton: { template: '<button><slot /></button>' },
  UCard: { template: '<div><slot name="header" /><slot /></div>' },
  UFormField: { template: '<div><slot /></div>' },
  USelect: { template: '<select />' },
  USwitch: { template: '<div />' },
  USkeleton: { template: '<div />' },
  UEmpty: { template: '<div />' },
  UBadge: { template: '<span />' },
  UModal: { template: '<div />' },
  UIcon: { template: '<span />' },
}

describe('format-import gating (lemma.importers capability)', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
  })

  it('hides the format-import section when lemma.importers is disabled', () => {
    const caps = useCapabilitiesStore()
    // Mark as already-loaded so ensureLoaded() is a no-op and we control the set directly.
    caps.loaded = true
    caps.enabledIds = new Set()

    const wrapper = mount(ImportExportPage, { global: { stubs: globalStubs } })

    expect(wrapper.find('[data-test="format-import"]').exists()).toBe(false)
  })

  it('shows the format-import section when lemma.importers is enabled', () => {
    const caps = useCapabilitiesStore()
    caps.loaded = true
    caps.enabledIds = new Set(['lemma.importers'])

    const wrapper = mount(ImportExportPage, { global: { stubs: globalStubs } })

    expect(wrapper.find('[data-test="format-import"]').exists()).toBe(true)
  })
})
