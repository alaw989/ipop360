import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FavoritesIndex from '@/Pages/Favorites/Index.vue'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        router: {
            get: vi.fn(),
            on: vi.fn(),
        },
        Link: { template: '<a><slot /></a>' },
        usePage: () => ({
            url: '/favorites',
            component: 'Favorites/Index',
            props: {},
        }),
    }
})

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SeoMeta: { template: '<div />' },
    RestaurantCard: { template: '<div class="restaurant-card" />' },
}

function createMockFavorites(count: number) {
    return Array.from({ length: count }, (_, i) => ({
        id: i + 1,
        name: `Restaurant ${i + 1}`,
        slug: `restaurant-${i + 1}`,
        city: 'Test City',
        state: 'TS',
        cuisine_names: ['Italian'],
        google_rating: 4.5,
        google_review_count: 100,
        quality_score: 80,
        price_range: '$$',
        primary_image: null,
        latitude: null,
        longitude: null,
        phone: null,
        address: null,
        url: null,
    }))
}

function mountFavorites(props = {}) {
    return mount(FavoritesIndex, {
        props: { favorites: [], ...props },
        global: { stubs },
    })
}

describe('Favorites/Index page', () => {
    it('renders the My Favorites title', () => {
        const wrapper = mountFavorites()
        expect(wrapper.text()).toContain('My Favorites')
    })

    it('renders the back to search link', () => {
        const wrapper = mountFavorites()
        expect(wrapper.text()).toContain('Back to search')
    })

    it('shows empty state when favorites array is empty', () => {
        const wrapper = mountFavorites({ favorites: [] })
        expect(wrapper.text()).toContain('No saved restaurants yet')
        expect(wrapper.text()).toContain('Browse restaurants')
    })

    it('shows header with heart icon', () => {
        const wrapper = mountFavorites()
        expect(wrapper.text()).toContain('Your saved restaurants')
    })

    it('renders RestaurantCard for each favorite', () => {
        const favorites = createMockFavorites(3)
        const wrapper = mountFavorites({ favorites })
        const cards = wrapper.findAll('.restaurant-card')
        expect(cards).toHaveLength(3)
    })

    it('does not show empty state when favorites exist', () => {
        const favorites = createMockFavorites(1)
        const wrapper = mountFavorites({ favorites })
        expect(wrapper.text()).not.toContain('No saved restaurants yet')
    })

    it('renders the favorites grid with responsive classes', () => {
        const favorites = createMockFavorites(2)
        const wrapper = mountFavorites({ favorites })
        const grid = wrapper.find('.grid')
        expect(grid.exists()).toBe(true)
    })
})
