import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FavoritesIndex from '@/Pages/Favorites/Index.vue'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Link: { template: '<a><slot /></a>' },
    }
})

vi.mock('@/composables/useSeo', () => ({
    useSeo: vi.fn((options: any) => ({
        title: options.title,
        description: options.description,
        canonical: options.url || '',
        noindex: false,
        ogTitle: options.title,
        ogDescription: options.description,
        ogType: options.type || 'website',
        ogUrl: options.url || '',
        ogSiteName: 'iPop360',
        ogImage: '/img/ipop360-og.png',
        ogImageAlt: 'iPop360 logo',
        twitterCard: 'summary',
        twitterTitle: options.title,
        twitterDescription: options.description,
        twitterImage: '/img/ipop360-og.png',
    })),
}))

vi.mock('@/composables/useBaseUrl', () => ({
    useBaseUrl: vi.fn(() => ({ value: 'http://localhost' })),
}))

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SeoMeta: { template: '<div />' },
    RestaurantCard: { template: '<div class="restaurant-card-stub">{{ restaurant.name }}</div>', props: ['restaurant', 'rank'] },
    Heart: { template: '<svg />' },
    Head: { template: '<div />' },
}

function makeRestaurant(overrides: Partial<Record<string, any>> = {}) {
    return {
        id: 1,
        name: 'Test Restaurant',
        slug: 'test-restaurant',
        description: null,
        address: null,
        city: null,
        state: null,
        lat: null,
        lng: null,
        photo_url: null,
        price_range: null,
        phone: null,
        website_url: null,
        google_rating: null,
        google_review_count: 0,
        yelp_rating: null,
        yelp_review_count: 0,
        has_award: false,
        popularity_score: 50,
        rank_change: null,
        distance: null,
        cuisines: [],
        source: null,
        ...overrides,
    }
}

function mountFavorites(favorites: ReturnType<typeof makeRestaurant>[] = []) {
    return mount(FavoritesIndex, {
        props: { favorites },
        global: { stubs },
    })
}

describe('Favorites page', () => {
    it('renders the page heading', () => {
        const wrapper = mountFavorites()
        expect(wrapper.text()).toContain('My Favorites')
    })

    it('shows back to search link', () => {
        const wrapper = mountFavorites()
        expect(wrapper.text()).toContain('Back to search')
    })

    it('shows empty state when favorites is empty', () => {
        const wrapper = mountFavorites()
        expect(wrapper.text()).toContain('No saved restaurants yet')
        expect(wrapper.text()).toContain('Browse restaurants')
    })

    it('renders restaurant cards when favorites has items', () => {
        const favs = [
            makeRestaurant({ id: 1, name: 'Pizza Place' }),
            makeRestaurant({ id: 2, name: 'Sushi Bar' }),
        ]
        const wrapper = mountFavorites(favs)
        const cards = wrapper.findAll('.restaurant-card-stub')
        expect(cards).toHaveLength(2)
        expect(cards[0].text()).toBe('Pizza Place')
        expect(cards[1].text()).toBe('Sushi Bar')
    })

    it('does not show empty state when favorites exist', () => {
        const wrapper = mountFavorites([makeRestaurant()])
        expect(wrapper.text()).not.toContain('No saved restaurants yet')
    })

    it('does not show the grid container when favorites is empty', () => {
        const wrapper = mountFavorites()
        expect(wrapper.text()).not.toContain('Test Restaurant')
    })
})
