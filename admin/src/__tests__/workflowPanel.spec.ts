import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { WorkflowState } from '@/queries/workflow'

const stateData = ref<WorkflowState | undefined>(undefined)
const mutate = vi.hoisted(() => ({
  submit: vi.fn().mockResolvedValue(undefined),
  approve: vi.fn().mockResolvedValue(undefined),
  requestChanges: vi.fn().mockResolvedValue(undefined),
  withdraw: vi.fn().mockResolvedValue(undefined),
}))

vi.mock('@/queries/workflow', () => ({
  useWorkflowState: () => ({ data: stateData }),
  useWorkflowMutations: () => ({
    submit: { mutateAsync: mutate.submit, isLoading: ref(false) },
    approve: { mutateAsync: mutate.approve, isLoading: ref(false) },
    requestChanges: { mutateAsync: mutate.requestChanges, isLoading: ref(false) },
    withdraw: { mutateAsync: mutate.withdraw, isLoading: ref(false) },
  }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), warning: vi.fn(), error: vi.fn() }),
}))

import WorkflowPanel from '@/pages/content/[type]/[uuid]/components/WorkflowPanel.vue'

const wf = (state: WorkflowState['state'], history: WorkflowState['history'] = []): WorkflowState => ({
  state,
  submitted_by: null,
  submitted_at: null,
  reviewed_by: null,
  reviewed_at: null,
  history,
})

const mountPanel = () =>
  mount(WorkflowPanel, { props: { uuid: 'e-1', locale: 'en', enabled: true } })

describe('WorkflowPanel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    stateData.value = undefined
    Object.values(mutate).forEach((m) => m.mockClear())
  })

  it('shows the state badge and only Submit for a draft', async () => {
    stateData.value = wf('draft')
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="workflow-state"]').text()).toBe('Draft')
    expect(wrapper.find('[data-test="workflow-submit"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="workflow-approve"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="workflow-withdraw"]').exists()).toBe(false)
  })

  it('shows reviewer actions in_review and submit calls the mutation', async () => {
    stateData.value = wf('in_review')
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="workflow-approve"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="workflow-request-changes"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="workflow-withdraw"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="workflow-submit"]').exists()).toBe(false)

    await wrapper.find('[data-test="workflow-approve"]').trigger('click')
    expect(mutate.approve).toHaveBeenCalledTimes(1)
  })

  it('request-changes requires a note before confirm fires', async () => {
    stateData.value = wf('in_review')
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.find('[data-test="workflow-request-changes"]').trigger('click')
    const confirm = wrapper.find('[data-test="workflow-request-changes-confirm"]')
    expect(confirm.exists()).toBe(true)
    expect(confirm.attributes('disabled')).toBeDefined()
    await confirm.trigger('click')
    expect(mutate.requestChanges).not.toHaveBeenCalled()

    await wrapper.find('[data-test="workflow-request-changes-note"] textarea, textarea[data-test="workflow-request-changes-note"]').setValue('tighten the intro')
    await wrapper.find('[data-test="workflow-request-changes-confirm"]').trigger('click')
    expect(mutate.requestChanges).toHaveBeenCalledWith('tighten the intro')
  })

  it('shows the reviewer note while changes are requested, with Submit to resubmit', async () => {
    stateData.value = wf('changes_requested', [
      {
        from_state: 'in_review',
        to_state: 'changes_requested',
        action: 'request_changes',
        actor_uuid: 'rev-1',
        note: 'fix the headline',
        created_at: null,
      },
    ])
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="workflow-state"]').text()).toBe('Changes requested')
    expect(wrapper.find('[data-test="workflow-last-note"]').text()).toBe('fix the headline')
    expect(wrapper.find('[data-test="workflow-submit"]').exists()).toBe(true)
  })

  it('approved shows only the badge', async () => {
    stateData.value = wf('approved')
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="workflow-state"]').text()).toBe('Approved')
    expect(wrapper.find('[data-test="workflow-submit"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="workflow-approve"]').exists()).toBe(false)
  })
})
