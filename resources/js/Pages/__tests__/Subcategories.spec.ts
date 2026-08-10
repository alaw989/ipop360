import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Subcategories from '@/Pages/Cuisine/Subcategories.vue'

const { mockVisit } = vi.hoisted(() => ({
    mockVisit: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Head: { template: '<div />' },
        router: { visit: mockVisit },
    }
})

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SubcategoryCard: {
        props: ['cuisine'],
        emits: ['select'],
        template: '<button class="subcategory-card" @click="$emit(\'select\', cuisine.slug)">{{ cuisine.name }}</button>',
    },
}

function makeCategory(overrides: Record<string, any> = {}) {
    return {
        id: 1,
        name: 'Italian',
        slug: 'italian',
        description: 'Delicious Italian food',
        icon: '🍝',
        cuisines: [
            { id: 1, name: 'Pizza', slug: 'pizza', description: null, icon: '🍕' },
            { id: 2, name: 'Pasta', slug: 'pasta', description: 'Fresh pasta', icon: '🍝' },
        ],
        ...overrides,
    }
}

function mountSubcategories(propsOverrides: Record<string, any> = {}) {
    return mount(Subcategories, {
        props: {
            category: makeCategory(),
            coords: { lat: undefined, lng: undefined },
            ...propsOverrides,
        },
        global: { stubs },
    })
}

describe('Cuisine Subcategories page', () => {
    it('renders the category name in the heading', () => {
        const wrapper = mountSubcategories()
        expect(wrapper.text()).toContain('Italian Cuisine')
    })

    it('renders the category icon', () => {
        const wrapper = mountSubcategories()
        expect(wrapper.text()).toContain('🍝')
    })

    it('renders a back link to categories', () => {
        const wrapper = mountSubcategories()
        const backLink = wrapper.find('a[href="/"]')
        expect(backLink.exists()).toBe(true)
        expect(backLink.text()).toContain('Back to categories')
    })

    it('renders the category description when present', () => {
        const wrapper = mountSubcategories()
        expect(wrapper.text()).toContain('Delicious Italian food')
    })

    it('does not render description when absent', () => {
        const wrapper = mountSubcategories({
            category: makeCategory({ description: null }),
        })
        expect(wrapper.text()).not.toContain('Delicious Italian food')
    })

    it('renders a SubcategoryCard for each cuisine', () => {
        const wrapper = mountSubcategories()
        const cards = wrapper.findAll('.subcategory-card')
        expect(cards).toHaveLength(2)
        expect(cards[0].text()).toBe('Pizza')
        expect(cards[1].text()).toBe('Pasta')
    })

    it('navigates to /restaurants when a cuisine is selected', async () => {
        mockVisit.mockClear()
        const wrapper = mountSubcategories()
        await wrapper.findAll('.subcategory-card')[0].trigger('click')
        expect(mockVisit).toHaveBeenCalledWith('/restaurants', {
            data: { cuisine: 'pizza' },
        })
    })

    it('includes coords in navigation when available', async () => {
        mockVisit.mockClear()
        const wrapper = mountSubcategories({
            coords: { lat: '40.7128', lng: '-74.0060' },
        })
        await wrapper.findAll('.subcategory-card')[0].trigger('click')
        expect(mockVisit).toHaveBeenCalledWith('/restaurants', {
            data: { cuisine: 'pizza', lat: '40.7128', lng: '-74.0060' },
        })
    })
})
