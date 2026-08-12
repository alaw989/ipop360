import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import StatsBand from '@/Components/StatsBand.vue'

const defaultProps = {
    stats: { restaurants: 1234, cuisines: 56, cities: 78 },
}

describe('StatsBand', () => {
    it('renders the three stat labels', () => {
        const wrapper = mount(StatsBand, { props: defaultProps })
        expect(wrapper.text()).toContain('Restaurants')
        expect(wrapper.text()).toContain('Cuisines')
        expect(wrapper.text()).toContain('Cities')
    })

    it('renders the restaurant count formatted with thousands separators', () => {
        const wrapper = mount(StatsBand, { props: defaultProps })
        expect(wrapper.text()).toContain('1,234')
    })

    it('renders cuisine and city counts', () => {
        const wrapper = mount(StatsBand, { props: defaultProps })
        expect(wrapper.text()).toContain('56')
        expect(wrapper.text()).toContain('78')
    })

    it('renders zero counts without error', () => {
        const wrapper = mount(StatsBand, {
            props: { stats: { restaurants: 0, cuisines: 0, cities: 0 } },
        })
        expect(wrapper.text()).toContain('0')
    })

    it('renders the section as a full-width band', () => {
        const wrapper = mount(StatsBand, { props: defaultProps })
        const section = wrapper.find('section')
        expect(section.classes()).toContain('w-full')
        expect(section.classes()).toContain('bg-background')
    })
})
