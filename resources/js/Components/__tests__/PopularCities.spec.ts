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

    it('renders a link per city with a city+state href', () => {
        const wrapper = mount(PopularCities, { props: defaultProps })
        const links = wrapper.findAll('a')
        expect(links).toHaveLength(2)
        expect(links[0].attributes('href')).toBe('/restaurants?city=Chicago&state=IL')
        expect(links[0].text()).toBe('Chicago')
        expect(links[1].attributes('href')).toBe('/restaurants?city=Los%20Angeles&state=CA')
        expect(links[1].text()).toBe('Los Angeles')
    })

    it('renders city links as pill-shaped chips', () => {
        const wrapper = mount(PopularCities, { props: defaultProps })
        const links = wrapper.findAll('a')
        for (const link of links) {
            expect(link.classes()).toContain('rounded-full')
            expect(link.classes()).toContain('border')
        }
    })

    it('renders empty grid when no cities given', () => {
        const wrapper = mount(PopularCities, { props: { cities: [] } })
        expect(wrapper.findAll('a')).toHaveLength(0)
    })
})
