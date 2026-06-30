import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import FieldEditor from '@/pages/collections/components/FieldEditor.vue'
import DropConfirmModal from '@/pages/collections/components/DropConfirmModal.vue'

describe('collections FieldEditor', () => {
  it('emits the add-field payload with the typed name and default type', async () => {
    const wrapper = mount(FieldEditor)

    await wrapper.find('input').setValue('title')
    await wrapper.find('[data-test="add-field"]').trigger('click')

    const events = wrapper.emitted('add')
    expect(events).toHaveLength(1)
    expect(events![0][0]).toEqual({ name: 'title', type: 'collections.text', settings: {} })
  })

  it('does not emit when the name is empty', async () => {
    const wrapper = mount(FieldEditor)
    await wrapper.find('[data-test="add-field"]').trigger('click')
    expect(wrapper.emitted('add')).toBeUndefined()
  })
})

describe('collections DropConfirmModal', () => {
  // UModal teleports its body/footer out of the wrapper; stub it to render the slots inline.
  const stubs = {
    Modal: { props: ['open'], template: '<div v-if="open"><slot name="body" /><slot name="footer" /></div>' },
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
