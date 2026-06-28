import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

vi.mock('@/queries/entries', () => ({
  useEntries: () => ({
    data: ref({
      entries: [
        { uuid: 'a1', display_title: 'Alpha' },
        { uuid: 'b2', display_title: 'Bravo' },
        { uuid: 'c3', display_title: 'Charlie' },
      ],
    }),
  }),
}))

import MultiReferencePicker from './MultiReferencePicker.vue'

describe('MultiReferencePicker', () => {
  it('preserves selection order on add and supports remove', async () => {
    const wrapper = mount(MultiReferencePicker, {
      props: { target: 'tag', modelValue: [] },
    })
    ;(wrapper.vm as unknown as { add: (u: string) => void }).add('c3')
    ;(wrapper.vm as unknown as { add: (u: string) => void }).add('a1')
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toEqual(['c3', 'a1'])
    ;(wrapper.vm as unknown as { remove: (u: string) => void }).remove('c3')
    expect(wrapper.emitted('update:modelValue')?.at(-1)?.[0]).toEqual(['a1'])
  })

  it('blocks adding past maxItems', () => {
    const wrapper = mount(MultiReferencePicker, {
      props: { target: 'tag', modelValue: ['a1', 'b2'], maxItems: 2 },
    })
    ;(wrapper.vm as unknown as { add: (u: string) => void }).add('c3')
    expect(wrapper.emitted('update:modelValue') ?? []).toEqual([])
  })
})
