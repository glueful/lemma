import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount, flushPromises } from '@vue/test-utils'
import { ref } from 'vue'
import type { NavMenuDetail, NavMenuSummary } from '@/queries/navigation'
import { ApiError } from '@/api/errors'

const menusData = ref<NavMenuSummary[] | undefined>(undefined)
const detailData = ref<NavMenuDetail | undefined>(undefined)
const refetch = vi.fn().mockResolvedValue(undefined)
const saveMock = vi.fn()
const notify = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/queries/navigation', () => ({
  useNavMenus: () => ({ data: menusData }),
  useNavMenu: () => ({ data: detailData, refetch }),
  useNavigationMutations: () => ({
    create: { mutateAsync: vi.fn() },
    rename: { mutateAsync: vi.fn() },
    remove: { mutateAsync: vi.fn() },
    save: { mutateAsync: saveMock },
  }),
}))
vi.mock('@/queries/locales', () => ({
  useLocales: () => ({ data: ref([{ code: 'en' }, { code: 'fr' }]) }),
}))
vi.mock('@/stores/capabilities', () => ({
  useCapabilitiesStore: () => ({ isEnabled: () => true }),
}))
vi.mock('@/composables/useNotify', () => ({
  useNotify: () => ({ success: notify.success, error: notify.error }),
}))
// Nuxt UI's Link override pulls useRoute from vue-router/auto (UButton renders through it).
vi.mock('vue-router/auto', () => ({
  useRoute: () => ({ path: '/navigation', params: {}, query: {} }),
  useRouter: () => ({ push: vi.fn(), resolve: vi.fn() }),
}))

import NavigationPage from '@/pages/navigation/index.vue'

const detail = (): NavMenuDetail => ({
  slug: 'main',
  name: 'Main',
  locale: 'en',
  lock_version: 1,
  items: [{ uuid: 'u-1', kind: 'url', url: '/about', labels: { en: 'About' }, children: [] }],
})

describe('navigation page', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    menusData.value = undefined
    detailData.value = undefined
    saveMock.mockReset()
    refetch.mockClear()
    notify.success.mockClear()
    notify.error.mockClear()
  })

  it('lists menus and shows the empty state without a selection', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 1, lock_version: 1 }]
    const wrapper = mount(NavigationPage)
    await flushPromises()

    expect(wrapper.findAll('[data-test="nav-menu-row"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Select or create a menu.')
  })

  it('selecting a menu renders the editor and save sends the working tree', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 1, lock_version: 1 }]
    const wrapper = mount(NavigationPage)
    await flushPromises()
    await wrapper.find('[data-test="nav-menu-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    expect(wrapper.findAll('[data-test="tree-item"]')).toHaveLength(1)

    // Make an edit so Save enables, then save.
    await wrapper.find('[data-test="tree-add-root"]').trigger('click')
    saveMock.mockResolvedValue(detail())
    await wrapper.find('[data-test="tree-save"]').trigger('click')
    await flushPromises()

    expect(saveMock).toHaveBeenCalledTimes(1)
    const arg = saveMock.mock.calls[0]![0] as { slug: string; lockVersion: number; items: unknown[] }
    expect(arg.slug).toBe('main')
    expect(arg.lockVersion).toBe(1)
    expect(arg.items).toHaveLength(2)
    expect(notify.success).toHaveBeenCalled()
  })

  it('a 409 on save reloads the menu and notifies instead of overwriting', async () => {
    menusData.value = [{ slug: 'main', name: 'Main', item_count: 1, lock_version: 1 }]
    const wrapper = mount(NavigationPage)
    await flushPromises()
    await wrapper.find('[data-test="nav-menu-row"]').trigger('click')
    detailData.value = detail()
    await flushPromises()

    await wrapper.find('[data-test="tree-add-root"]').trigger('click')
    saveMock.mockRejectedValue(new ApiError('conflict', 409, {}, null))
    await wrapper.find('[data-test="tree-save"]').trigger('click')
    await flushPromises()

    expect(refetch).toHaveBeenCalledTimes(1)
    expect(notify.error).toHaveBeenCalled()
    expect(notify.success).not.toHaveBeenCalled()
  })
})
