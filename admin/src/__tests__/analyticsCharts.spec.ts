import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import AnalyticsLineChart from '@/pages/analytics/components/AnalyticsLineChart.vue'
import AnalyticsBarChart from '@/pages/analytics/components/AnalyticsBarChart.vue'

// Stub unovis primitives — jsdom can't lay out real SVG charts, and they're not what we're testing.
const stubs = {
  VisXYContainer: { template: '<div><slot /></div>' },
  VisLine: true,
  VisStackedBar: true,
  VisAxis: true,
  VisTooltip: true,
}

describe('analytics chart wrappers', () => {
  it('line chart renders its container given series', () => {
    const wrapper = mount(AnalyticsLineChart, {
      props: {
        series: [{ key: 'logins', label: 'Logins', color: '#000', points: [{ day: '2025-06-10', count: 3 }] }],
      },
      global: { stubs },
    })
    expect(wrapper.find('[data-test="analytics-line-chart"]').exists()).toBe(true)
  })

  it('bar chart renders bars when items exist', () => {
    const wrapper = mount(AnalyticsBarChart, {
      props: { items: [{ subject: 'posts', count: 5 }] },
      global: { stubs },
    })
    expect(wrapper.find('[data-test="analytics-bar-chart"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="analytics-bar-empty"]').exists()).toBe(false)
  })

  it('bar chart shows the empty state when there are no items', () => {
    const wrapper = mount(AnalyticsBarChart, { props: { items: [] }, global: { stubs } })
    expect(wrapper.find('[data-test="analytics-bar-empty"]').exists()).toBe(true)
  })
})
