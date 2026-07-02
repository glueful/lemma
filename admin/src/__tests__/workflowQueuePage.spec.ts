import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises, RouterLinkStub } from '@vue/test-utils'
import { ref } from 'vue'
import type { WorkflowQueuePage } from '@/queries/workflow'

const queueData = ref<WorkflowQueuePage | undefined>(undefined)
const queueError = ref<unknown>(null)

vi.mock('@/queries/workflow', () => ({
  useWorkflowQueue: () => ({ data: queueData, isLoading: ref(false), error: queueError }),
}))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: () => true }),
}))
vi.mock('vue-router/auto', () => ({}))

import WorkflowQueuePageComponent from '@/pages/workflow/index.vue'

const mountPage = () =>
  mount(WorkflowQueuePageComponent, {
    global: { stubs: { RouterLink: RouterLinkStub } },
  })

describe('workflow review queue page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    queueData.value = undefined
    queueError.value = null
  })

  it('renders queue rows linking to the entry editor', async () => {
    queueData.value = {
      items: [
        {
          entry_uuid: 'e-1',
          locale: 'en',
          submitted_by: 'author-1',
          submitted_at: '2026-07-01 10:00:00',
          title: 'Hello',
          type_slug: 'blog',
        },
      ],
      total: 1,
      page: 1,
      perPage: 25,
    }
    const wrapper = mountPage()
    await flushPromises()

    const rows = wrapper.findAll('[data-test="workflow-queue-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0]!.text()).toContain('Hello')
    expect(rows[0]!.text()).toContain('blog')
    const link = wrapper.findComponent(RouterLinkStub)
    expect(link.props('to')).toBe('/content/blog/e-1?locale=en')
  })

  it('shows the empty state when nothing is in review', async () => {
    queueData.value = { items: [], total: 0, page: 1, perPage: 25 }
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="workflow-queue-empty"]').exists()).toBe(true)
    expect(wrapper.findAll('[data-test="workflow-queue-row"]')).toHaveLength(0)
  })

  it('shows the error state when the queue cannot load', async () => {
    queueError.value = new Error('403')
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="workflow-queue-error"]').exists()).toBe(true)
  })
})
