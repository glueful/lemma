import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const TWO_COLLECTIONS = [
  {
    name: 'posts',
    label: 'Posts',
    fields: [{ name: 'title', type: 'collections.text', settings: {} }],
    accessPolicy: { read: 'public', write: 'scoped', delete: 'scoped' },
  },
  {
    name: 'orders',
    label: 'Orders',
    fields: [],
    accessPolicy: { read: 'scoped', write: 'scoped', delete: 'scoped' },
  },
]

vi.mock('@/queries/collections', () => ({
  useCollections: () => ({ data: ref(TWO_COLLECTIONS), status: ref('success') }),
  useCollectionMutations: () => ({ remove: { mutateAsync: vi.fn(), isLoading: ref(false) } }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import CollectionsListPane from '@/pages/collections/components/CollectionsListPane.vue'

describe('collections list pane', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('renders one row per collection and a create link', () => {
    const wrapper = mount(CollectionsListPane, {
      props: { selectedName: undefined },
      global: {
        // No router in the unit env; stub RouterLink to a plain anchor that preserves `to`.
        stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } },
      },
    })

    expect(wrapper.findAll('[data-test="collection-row"]')).toHaveLength(2)

    const newButton = wrapper.find('[data-test="new-collection"]')
    expect(newButton.exists()).toBe(true)
    expect(wrapper.html()).toContain('/collections/new')
  })

  it('emits select with the collection when a row is clicked', async () => {
    const wrapper = mount(CollectionsListPane, {
      props: { selectedName: undefined },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } },
    })

    await wrapper.findAll('[data-test="collection-row"]')[0].trigger('click')

    expect(wrapper.emitted('select')?.[0]?.[0]).toMatchObject({ name: 'posts' })
  })
})
