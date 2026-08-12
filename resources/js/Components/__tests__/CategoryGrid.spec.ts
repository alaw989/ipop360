import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import CategoryGrid from '@/Components/CategoryGrid.vue'

const stubs = { Skeleton: true }

const categories = [
    { id: 1, name: 'Italian', slug: 'italian', icon: '🍝' },
    { id: 2, name: 'Mexican', slug: 'mexican', icon: null },
]

const defaultProps = { categories }

describe('CategoryGrid', () => {
    it('renders the heading', () => {
        const wrapper = mount(CategoryGrid, { props: defaultProps, global: { stubs } })
        expect(wrapper.text()).toContain('Categories')
    })

    it('renders a link per category with correct href and icon', () => {
        const wrapper = mount(CategoryGrid, { props: defaultProps, global: { stubs } })
        const links = wrapper.findAll('a')
        expect(links).toHaveLength(2)
        expect(links[0].attributes('href')).toBe('/search?category=italian')
        expect(links[0].text()).toContain('🍝')
        expect(links[0].text()).toContain('Italian')
    })

    it('appends lat/lng to href when lat and lng are provided', () => {
        const wrapper = mount(CategoryGrid, {
            props: { ...defaultProps, lat: 40.7128, lng: -74.006 },
            global: { stubs },
        })
        const href = wrapper.find('a').attributes('href')
        expect(href).toBe('/search?category=italian&lat=40.7128&lng=-74.006')
    })

    it('omits lat/lng query params when lat or lng is null', () => {
        const wrapper = mount(CategoryGrid, {
            props: { ...defaultProps, lat: null, lng: null },
            global: { stubs },
        })
        expect(wrapper.find('a').attributes('href')).toBe('/search?category=italian')
    })

    it('omits lat/lng query params when lat/lng props are absent', () => {
        const wrapper = mount(CategoryGrid, { props: defaultProps, global: { stubs } })
        expect(wrapper.find('a').attributes('href')).toBe('/search?category=italian')
    })

    it('omits icon emoji when icon is null', () => {
        const wrapper = mount(CategoryGrid, { props: defaultProps, global: { stubs } })
        const mexicanLink = wrapper.findAll('a')[1].text()
        expect(mexicanLink).not.toContain('null')
        expect(mexicanLink).toContain('Mexican')
    })

    it('renders category links as pill-shaped chips', () => {
        const wrapper = mount(CategoryGrid, { props: defaultProps, global: { stubs } })
        const links = wrapper.findAll('a')
        expect(links).toHaveLength(2)
        for (const link of links) {
            expect(link.classes()).toContain('rounded-full')
            expect(link.classes()).toContain('border')
        }
    })

    it('renders 8 skeletons while loading and no category links', () => {
        const wrapper = mount(CategoryGrid, {
            props: { ...defaultProps, loading: true },
            global: { stubs },
        })
        expect(wrapper.findAll('a')).toHaveLength(0)
        expect(wrapper.findAll('[data-testid="category-skeleton"]')).toHaveLength(8)
    })

    it('renders empty grid when no categories given', () => {
        const wrapper = mount(CategoryGrid, {
            props: { categories: [] },
            global: { stubs },
        })
        expect(wrapper.findAll('a')).toHaveLength(0)
    })

    it('keys each skeleton uniquely', () => {
        const wrapper = mount(CategoryGrid, {
            props: { ...defaultProps, loading: true },
            global: { stubs },
        })
        const skeletonDivs = wrapper.findAll('[data-testid="category-skeleton"]')
        expect(skeletonDivs).toHaveLength(8)
    })
})
