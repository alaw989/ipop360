import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PopularCuisines from '@/Components/PopularCuisines.vue'

const stubs = { Skeleton: true, ChevronDown: true }

const cuisines = Array.from({ length: 15 }, (_, i) => ({
    id: i + 1,
    name: `Cuisine ${i + 1}`,
    slug: `cuisine-${i + 1}`,
    icon: i === 0 ? '🍝' : null,
}))

const defaultProps = { cuisines, city: 'Miami' }

describe('PopularCuisines', () => {
    it('renders the heading with the city', () => {
        const wrapper = mount(PopularCuisines, { props: defaultProps, global: { stubs } })
        expect(wrapper.find('h2').text()).toContain('Popular cuisines')
        expect(wrapper.find('h2').text()).toContain('in Miami')
    })

    it('omits city from heading when city is null', () => {
        const wrapper = mount(PopularCuisines, {
            props: { ...defaultProps, city: null },
            global: { stubs },
        })
        expect(wrapper.find('h2').text()).toBe('Popular cuisines')
    })

    it('renders at most 12 cuisines initially with correct hrefs', () => {
        const wrapper = mount(PopularCuisines, { props: defaultProps, global: { stubs } })
        const links = wrapper.findAll('a')
        expect(links).toHaveLength(12)
        expect(links[0].attributes('href')).toBe('/search?cuisine=cuisine-1')
        expect(links[0].text()).toContain('🍝')
        expect(links[0].text()).toContain('Cuisine 1')
    })

    it('appends lat/lng to href when both provided', () => {
        const wrapper = mount(PopularCuisines, {
            props: { ...defaultProps, lat: 25.7617, lng: -80.1918 },
            global: { stubs },
        })
        expect(wrapper.find('a').attributes('href')).toBe(
            '/search?cuisine=cuisine-1&lat=25.7617&lng=-80.1918'
        )
    })

    it('omits lat/lng query params when lat/lng are null', () => {
        const wrapper = mount(PopularCuisines, {
            props: { ...defaultProps, lat: null, lng: null },
            global: { stubs },
        })
        expect(wrapper.find('a').attributes('href')).toBe('/search?cuisine=cuisine-1')
    })

    it('shows a "Show more" button when there are more than 12 cuisines', () => {
        const wrapper = mount(PopularCuisines, { props: defaultProps, global: { stubs } })
        expect(wrapper.text()).toContain('Show more')
    })

    it('expands to all cuisines and shows "Show less" after clicking Show more', async () => {
        const wrapper = mount(PopularCuisines, { props: defaultProps, global: { stubs } })
        await wrapper.find('button').trigger('click')
        expect(wrapper.findAll('a')).toHaveLength(15)
        expect(wrapper.text()).toContain('Show less')
        await wrapper.find('button').trigger('click')
        expect(wrapper.findAll('a')).toHaveLength(12)
    })

    it('does not render a Show more button when 12 or fewer cuisines', () => {
        const wrapper = mount(PopularCuisines, {
            props: { ...defaultProps, cuisines: cuisines.slice(0, 10) },
            global: { stubs },
        })
        expect(wrapper.find('button').exists()).toBe(false)
        expect(wrapper.findAll('a')).toHaveLength(10)
    })

    it('renders 12 skeletons while loading and no cuisine links', () => {
        const wrapper = mount(PopularCuisines, {
            props: { ...defaultProps, loading: true },
            global: { stubs },
        })
        expect(wrapper.findAll('a')).toHaveLength(0)
        expect(wrapper.findAll('button')).toHaveLength(0)
    })

    it('renders nothing when cuisines list is empty', () => {
        const wrapper = mount(PopularCuisines, {
            props: { ...defaultProps, cuisines: [] },
            global: { stubs },
        })
        expect(wrapper.findAll('a')).toHaveLength(0)
    })

    it('renders cuisine links as pill-shaped chips', () => {
        const wrapper = mount(PopularCuisines, { props: defaultProps, global: { stubs } })
        const links = wrapper.findAll('a')
        expect(links.length).toBeGreaterThan(0)
        for (const link of links) {
            expect(link.classes()).toContain('rounded-full')
            expect(link.classes()).toContain('border')
        }
    })
})
