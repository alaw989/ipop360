import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PopularCities from '@/Components/PopularCities.vue'

const cities = [
    { name: 'Chicago', city: 'Chicago', state: 'IL' },
    { name: 'Los Angeles', city: 'Los Angeles', state: 'CA' },
]

const defaultProps = { cities }

describe('PopularCities', () => {
    it('renders the heading', () => {
        const wrapper = mount(PopularCities, { props: defaultProps })
        expect(wrapper.text()).toContain('Explore restaurants in popular cities')
    })

    it('renders a button per city', () => {
        const wrapper = mount(PopularCities, { props: defaultProps })
        const buttons = wrapper.findAll('button')
        expect(buttons).toHaveLength(2)
        expect(buttons[0].text()).toBe('Chicago')
        expect(buttons[1].text()).toBe('Los Angeles')
    })

    it('emits select with city and state when a chip is clicked', async () => {
        const wrapper = mount(PopularCities, { props: defaultProps })
        await wrapper.findAll('button')[0].trigger('click')
        expect(wrapper.emitted('select')).toEqual([[{ city: 'Chicago', state: 'IL' }]])
    })

    it('renders city chips as pill-shaped buttons', () => {
        const wrapper = mount(PopularCities, { props: defaultProps })
        for (const button of wrapper.findAll('button')) {
            expect(button.classes()).toContain('rounded-full')
            expect(button.classes()).toContain('border')
        }
    })

    it('highlights the selected city', () => {
        const wrapper = mount(PopularCities, { props: { ...defaultProps, selectedCity: 'Chicago' } })
        const buttons = wrapper.findAll('button')
        expect(buttons[0].classes()).toContain('border-primary')
        expect(buttons[1].classes()).not.toContain('border-primary')
    })

    it('renders no buttons when no cities given', () => {
        const wrapper = mount(PopularCities, { props: { cities: [] } })
        expect(wrapper.findAll('button')).toHaveLength(0)
    })
})
