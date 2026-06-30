import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

const updateScopesMock = vi.fn().mockResolvedValue(undefined)

vi.mock('@/queries/apiKeys', () => ({
  useApiKeyList: () => ({
    data: ref({ api_keys: [{ uuid: 'k1', name: 'Key 1', scopes: [] }] }),
    status: ref('success'),
  }),
  useApiKeyMutations: () => ({ updateScopes: { mutateAsync: updateScopesMock, isLoading: ref(false) } }),
}))
vi.mock('@/composables/useNotify', () => ({ useNotify: () => ({ error: vi.fn() }) }))

import ScopesPanel from '@/pages/collections/[name]/components/ScopesPanel.vue'

describe('collections scopes panel', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    updateScopesMock.mockClear()
  })

  it('toggling read calls the scope-update mutation with {name}.read', async () => {
    const wrapper = mount(ScopesPanel, { props: { collectionName: 'posts' } })

    expect(wrapper.findAll('[data-test="scope-key-row"]')).toHaveLength(1)

    await wrapper.find('[data-test="scope-read"]').trigger('click')

    expect(updateScopesMock).toHaveBeenCalledWith({ uuid: 'k1', scopes: ['posts.read'] })
  })
})
