import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import TablePagination from '@/components/TablePagination.vue'

vi.mock('vue-router', async (importOriginal) => {
  const actual = await importOriginal<typeof import('vue-router')>()
  return { ...actual, useRoute: () => ({ params: { name: 'posts' } }) }
})

vi.mock('@/queries/collections', () => ({
  useCollection: () => ({
    data: ref({
      name: 'posts',
      label: 'Posts',
      fields: [{ name: 'title', type: 'collections.string', settings: {} }],
      accessPolicy: { read: 'public', write: 'scoped', delete: 'scoped' },
    }),
  }),
  useCollectionRows: () => ({
    data: ref({
      rows: [
        { uuid: 'r1', title: 'First' },
        { uuid: 'r2', title: 'Second' },
      ],
      total: 2,
      page: 1,
      perPage: 20,
    }),
    status: ref('success'),
  }),
  useCollectionRowMutations: () => ({
    create: { mutateAsync: vi.fn(), isLoading: ref(false) },
    update: { mutateAsync: vi.fn(), isLoading: ref(false) },
    remove: { mutateAsync: vi.fn(), isLoading: ref(false) },
  }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: vi.fn(), error: vi.fn() }),
}))

import DataBrowser from '@/pages/collections/[name]/data/index.vue'

const stubs = {
  RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
  RowDrawer: { props: ['open'], template: '<div v-if="open" data-test="row-drawer" />' },
  // Not under test here, and they pull in the schema mutations/api-key queries.
  CollectionEditSlideover: true,
  CollectionCreateSlideover: true,
}

describe('collections data browser', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('renders one row per record, wires pagination, and opens the drawer', async () => {
    const wrapper = mount(DataBrowser, { global: { stubs } })

    expect(wrapper.findAll('[data-test="row"]')).toHaveLength(2)
    expect(wrapper.findComponent(TablePagination).props('total')).toBe(2)

    expect(wrapper.find('[data-test="row-drawer"]').exists()).toBe(false)
    await wrapper.find('[data-test="new-row"]').trigger('click')
    expect(wrapper.find('[data-test="row-drawer"]').exists()).toBe(true)
  })
})
