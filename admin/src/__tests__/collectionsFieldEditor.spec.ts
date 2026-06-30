import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

// FieldCard → FieldSettingsPanel pulls the collection list for relation targets; stub the module.
vi.mock('@/queries/collections', async () => {
  const { ref } = await import('vue')
  return {
    useCollections: () => ({ data: ref([]) }),
    COLLECTION_FIELD_TYPE_META: {
      'collections.text': { label: 'Text', icon: 'i-lucide-type' },
      'collections.integer': { label: 'Integer', icon: 'i-lucide-hash' },
    },
  }
})

import FieldCard from '@/pages/collections/components/FieldCard.vue'
import DropConfirmModal from '@/pages/collections/components/DropConfirmModal.vue'

describe('collections FieldCard', () => {
  it('draft card emits save and carries the typed name', async () => {
    const model = { name: '', type: 'collections.text' as const, settings: {}, open: true }
    const wrapper = mount(FieldCard, { props: { draft: true, modelValue: model } })

    await wrapper.find('[data-test="field-name"]').setValue('title')
    await wrapper.find('[data-test="save-field"]').trigger('click')

    expect(wrapper.emitted('save')).toBeTruthy()
    expect(model.name).toBe('title')
  })

  it('non-draft card emits remove from the trash button', async () => {
    const model = { name: 'title', type: 'collections.text' as const, settings: {}, open: false }
    const wrapper = mount(FieldCard, { props: { modelValue: model } })

    await wrapper.find('[aria-label="Remove field"]').trigger('click')

    expect(wrapper.emitted('remove')).toBeTruthy()
  })

  it('system card shows a System badge and hides the remove button', () => {
    const model = {
      name: 'id',
      type: 'collections.integer' as const,
      settings: {},
      open: false,
      system: true,
    }
    const wrapper = mount(FieldCard, { props: { modelValue: model } })

    expect(wrapper.text()).toContain('System')
    expect(wrapper.find('[aria-label="Remove field"]').exists()).toBe(false)
  })
})

describe('collections DropConfirmModal', () => {
  // UModal teleports its body/footer out of the wrapper; stub it to render the slots inline.
  const stubs = {
    Modal: {
      props: ['open'],
      template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>',
    },
  }

  it('keeps the drop button disabled until the exact name is typed', async () => {
    const wrapper = mount(DropConfirmModal, {
      props: { open: true, title: 'Drop collection', confirmName: 'posts', requireConfirm: true },
      global: { stubs },
    })

    const button = () => wrapper.find('[data-test="drop-confirm-button"]')
    expect(button().attributes('disabled')).toBeDefined()

    await wrapper.find('input').setValue('wrong')
    expect(button().attributes('disabled')).toBeDefined()

    await wrapper.find('input').setValue('posts')
    expect(button().attributes('disabled')).toBeUndefined()
  })

  it('emits the typed confirm token', async () => {
    const wrapper = mount(DropConfirmModal, {
      props: { open: true, title: 'Drop', confirmName: 'posts', requireConfirm: true },
      global: { stubs },
    })

    await wrapper.find('input').setValue('posts')
    await wrapper.find('[data-test="drop-confirm-button"]').trigger('click')
    expect(wrapper.emitted('confirm')?.[0]).toEqual(['posts'])
  })

  it('light path (empty table) confirms immediately with no token', async () => {
    const wrapper = mount(DropConfirmModal, {
      props: { open: true, title: 'Drop', confirmName: 'posts', requireConfirm: false },
      global: { stubs },
    })

    expect(wrapper.find('[data-test="drop-confirm-button"]').attributes('disabled')).toBeUndefined()
    await wrapper.find('[data-test="drop-confirm-button"]').trigger('click')
    expect(wrapper.emitted('confirm')?.[0]).toEqual([undefined])
  })
})
