import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const mockCallPhone = vi.fn()
const mockOpenWebsite = vi.fn()
const mockTrackDirections = vi.fn()

vi.mock('@/lib/restaurant', () => ({
    callPhone: (...args: any[]) => mockCallPhone(...args),
    openWebsite: (...args: any[]) => mockOpenWebsite(...args),
    trackDirections: (...args: any[]) => mockTrackDirections(...args),
}))

const mockToggle = vi.fn()
vi.mock('@/composables/useFavorites', () => ({
    useFavorites: () => ({
        isFavorited: (_r: any) => false,
        toggle: mockToggle,
    }),
}))

vi.mock('@/composables/useRestaurantDisplay', () => ({
    getDetailUrl: (r: any) => (r.id > 0 ? `/restaurants/${r.slug}` : `/maps?q=${r.name}`),
    getDisplayRating: (r: any) => (r.yelp_rating ? { rating: r.yelp_rating, count: r.yelp_review_count, source: 'Yelp' } : null),
    getMapCoords: (r: any) => (r.lat != null ? { lat: r.lat, lng: r.lng } : null),
    getRankStyle: (rank: number) => {
        if (rank === 1) return { bg: 'from-amber-400', text: 'text-white' }
        if (rank === 2) return { bg: 'from-slate-300', text: 'text-slate-900' }
        return { bg: 'from-gray-800', text: 'text-white' }
    },
    getRestaurantGradient: () => 'bg-gradient-to-br',
}))

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}))

vi.mock('@lucide/vue', () => ({
    Heart: { template: '<svg data-testid="heart-icon" class="h-4 w-4" />' },
    Navigation: { template: '<svg data-testid="navigation-icon" />' },
    Phone: { template: '<svg data-testid="phone-icon" />' },
    Globe: { template: '<svg data-testid="globe-icon" />' },
    ArrowUp: { template: '<svg data-testid="arrow-up-icon" class="h-2.5 w-2.5" />' },
    ArrowDown: { template: '<svg data-testid="arrow-down-icon" class="h-2.5 w-2.5" />' },
    Minus: { template: '<svg data-testid="minus-icon" class="h-2.5 w-2.5" />' },
}))

import SearchResultCard from '@/Components/SearchResultCard.vue'

function makeRestaurant(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        name: 'Test Restaurant',
        slug: 'test-restaurant',
        address: '123 Main St',
        city: 'New York',
        state: 'NY',
        cuisines: [],
        ...overrides,
    } as any
}

function mountCard(restaurant: Record<string, unknown> = {}, extraProps: Record<string, unknown> = {}) {
    return mount(SearchResultCard, {
        props: {
            restaurant: makeRestaurant(restaurant),
            rank: 1,
            searchLat: null,
            searchLng: null,
            ...extraProps,
        },
        global: {
            stubs: {
                StarRating: { template: '<div class="star-rating-stub"><span data-testid="star-rating">{{ rating }}</span></div>', props: ['rating', 'source', 'reviewCount', 'size'] },
                ScoreChip: { template: '<div class="score-chip-stub" :data-total="total"><slot /></div>', props: ['total', 'breakdown'] },
                Badge: { template: '<span class="badge" :class="variant">{{ variant }} <slot /></span>', props: ['variant'] },
            },
        },
    })
}

describe('SearchResultCard', () => {
    beforeEach(() => {
        mockCallPhone.mockClear()
        mockOpenWebsite.mockClear()
        mockTrackDirections.mockClear()
        mockToggle.mockClear()
    })

    describe('basic rendering', () => {
        it('renders the restaurant name', () => {
            const wrapper = mountCard({ name: 'Pizza Palace' })
            expect(wrapper.text()).toContain('Pizza Palace')
        })

        it('renders address when present', () => {
            const wrapper = mountCard({ address: '456 Elm St', city: 'Boston', state: null })
            expect(wrapper.text()).toContain('456 Elm St')
        })

        it('renders city and state when no address', () => {
            const wrapper = mountCard({ address: null, city: 'Chicago', state: 'IL' })
            expect(wrapper.text()).toContain('Chicago, IL')
        })

        it('renders city only when state is null', () => {
            const wrapper = mountCard({ address: null, city: 'Austin', state: null })
            expect(wrapper.text()).toContain('Austin')
        })

        it('renders an article element', () => {
            const wrapper = mountCard()
            expect(wrapper.find('article').exists()).toBe(true)
        })
    })

    describe('rank', () => {
        it('puts the rank in the name, as on Yelp', () => {
            const wrapper = mountCard({ name: 'Austhentico' }, { rank: 1 })
            expect(wrapper.get('[data-testid="rank"]').text()).toBe('1.')
            expect(wrapper.get('h2').text()).toContain('1. Austhentico')
        })

        it('uses a plain number for every rank, never an emoji or a pill', () => {
            const wrapper = mountCard({}, { rank: 5 })
            expect(wrapper.get('[data-testid="rank"]').text()).toBe('5.')
            expect(wrapper.text()).not.toContain('🔥')
            expect(wrapper.text()).not.toContain('#5')
        })
    })

    describe('photo badge', () => {
        it('shows at most one badge, and none for an ordinary restaurant', () => {
            expect(mountCard({ popularity_score: 0.5 }).find('[data-testid="photo-badge"]').exists()).toBe(false)
        })

        it('names a top tier on the photo', () => {
            expect(mountCard({ popularity_score: 0.85 }).get('[data-testid="photo-badge"]').text()).toBe('Top rated')
        })

        it('prefers an award over the tier', () => {
            const badges = mountCard({ has_award: true, popularity_score: 0.95 }).findAll('[data-testid="photo-badge"]')
            expect(badges).toHaveLength(1)
            expect(badges[0]!.text()).toBe('Award winner')
        })
    })

    describe('photo', () => {
        it('renders img when photo_url is set', () => {
            const wrapper = mountCard({ photo_url: 'https://example.com/photo.jpg' })
            const img = wrapper.find('img')
            expect(img.exists()).toBe(true)
            expect(img.attributes('src')).toBe('https://example.com/photo.jpg')
        })

        it('renders gradient fallback when no photo_url', () => {
            const wrapper = mountCard({ photo_url: null })
            expect(wrapper.find('img').exists()).toBe(false)
            expect(wrapper.text()).toContain('🍽')
        })
    })

    describe('award badge', () => {
        it('renders award badge when has_award is true', () => {
            const wrapper = mountCard({ has_award: true })
            expect(wrapper.text()).toContain('Award')
        })

        it('does not render award badge when has_award is false', () => {
            const wrapper = mountCard({ has_award: false })
            expect(wrapper.text()).not.toContain('Award')
        })
    })

    describe('StarRating', () => {
        it('renders StarRating when Yelp rating exists', () => {
            const wrapper = mountCard({ yelp_rating: 4.5, yelp_review_count: 200 })
            const stub = wrapper.find('[data-testid="star-rating"]')
            expect(stub.exists()).toBe(true)
            expect(stub.text()).toBe('4.5')
        })

        it('does not render StarRating when no ratings exist', () => {
            const wrapper = mountCard({ yelp_rating: null, google_rating: null })
            expect(wrapper.find('[data-testid="star-rating"]').exists()).toBe(false)
        })

        it('labels an unrated venue "Not yet rated" instead of showing stars', () => {
            const wrapper = mountCard({ yelp_rating: null, google_rating: null })
            expect(wrapper.find('[data-testid="not-yet-rated"]').text()).toBe('Not yet rated')
        })

        it('does not show the unrated label when a rating exists', () => {
            const wrapper = mountCard({ yelp_rating: 4.5, yelp_review_count: 200 })
            expect(wrapper.find('[data-testid="not-yet-rated"]').exists()).toBe(false)
        })
    })

    describe('price range', () => {
        it('renders price range when set', () => {
            const wrapper = mountCard({ price_range: '$$' })
            expect(wrapper.text()).toContain('$$')
        })

        it('does not render price range when not set', () => {
            const wrapper = mountCard({ price_range: null })
            expect(wrapper.text()).not.toContain('$$')
        })
    })

    describe('distance', () => {
        it('renders distance formatted to 1 decimal', () => {
            const wrapper = mountCard({ distance: 3.456 })
            expect(wrapper.text()).toContain('3.5 mi')
        })

        it('does not render distance when null', () => {
            const wrapper = mountCard({ distance: null })
            expect(wrapper.text()).not.toMatch(/\d+(\.\d+)? mi\b/)
        })
    })

    describe('review snippet', () => {
        it('renders description when present', () => {
            const wrapper = mountCard({ description: 'Amazing food and great service!' })
            expect(wrapper.text()).toContain('"Amazing food and great service!"')
        })

        it('truncates description over 120 characters', () => {
            const wrapper = mountCard({ description: 'A'.repeat(150) })
            const text = wrapper.text()
            expect(text).toContain('…')
            expect(text).toContain('Read more')
        })

        it('does not render snippet when no description', () => {
            const wrapper = mountCard({ description: null })
            expect(wrapper.text()).not.toContain('"')
        })
    })

    describe('cuisine badges', () => {
        it('renders cuisine badges', () => {
            const wrapper = mountCard({
                cuisines: [
                    { id: 1, name: 'Italian', slug: 'italian' },
                    { id: 2, name: 'Pizza', slug: 'pizza' },
                ],
            })
            expect(wrapper.text()).toContain('Italian')
            expect(wrapper.text()).toContain('Pizza')
        })

        it('links each cuisine to its search', () => {
            const wrapper = mountCard({
                cuisines: [{ id: 1, name: 'Italian', slug: 'italian' }],
            })
            expect(wrapper.get('[data-testid="meta"] a[href="/search?cuisine=italian"]').text()).toBe('Italian')
        })

        it('lists at most two cuisines in the meta line', () => {
            const wrapper = mountCard({
                cuisines: [
                    { id: 1, name: 'Italian', slug: 'italian' },
                    { id: 2, name: 'Pizza', slug: 'pizza' },
                    { id: 3, name: 'Pasta', slug: 'pasta' },
                ],
            })
            expect(wrapper.findAll('[data-testid="meta"] a')).toHaveLength(2)
        })
    })

    describe('action pills', () => {
        it('renders Directions when mapCoords exist', () => {
            const wrapper = mountCard({ lat: 40.7128, lng: -74.006 })
            expect(wrapper.text()).toContain('Directions')
            expect(wrapper.find('[data-testid="navigation-icon"]').exists()).toBe(true)
        })

        it('does not render Directions when no coords', () => {
            const wrapper = mountCard({ lat: null, lng: null })
            expect(wrapper.text()).not.toContain('Directions')
        })

        it('tracks directions on click', async () => {
            const wrapper = mountCard({ id: 42, lat: 40.7128, lng: -74.006 })
            const dirsBtn = wrapper.findAll('a').find(a => a.text() === 'Directions')
            await dirsBtn!.trigger('click')
            expect(mockTrackDirections).toHaveBeenCalledWith(42)
        })

        it('renders Call when phone exists', () => {
            const wrapper = mountCard({ phone: '+15551234567' })
            expect(wrapper.text()).toContain('Call')
            expect(wrapper.find('[data-testid="phone-icon"]').exists()).toBe(true)
        })

        it('does not render Call when no phone', () => {
            const wrapper = mountCard({ phone: null })
            expect(wrapper.text()).not.toContain('Call')
        })

        it('calls callPhone on Call click', async () => {
            const wrapper = mountCard({ id: 7, phone: '+15551234567' })
            const callBtn = wrapper.findAll('button').find(b => b.text() === 'Call')
            await callBtn!.trigger('click')
            expect(mockCallPhone).toHaveBeenCalledWith('+15551234567', 7)
        })

        it('renders Website when website_url exists', () => {
            const wrapper = mountCard({ website_url: 'https://example.com' })
            expect(wrapper.text()).toContain('Website')
            expect(wrapper.find('[data-testid="globe-icon"]').exists()).toBe(true)
        })

        it('does not render Website when no website_url', () => {
            const wrapper = mountCard({ website_url: null })
            expect(wrapper.text()).not.toContain('Website')
        })

        it('calls openWebsite on Website click', async () => {
            const wrapper = mountCard({ id: 7, website_url: 'https://example.com' })
            const webBtn = wrapper.findAll('button').find(b => b.text() === 'Website')
            await webBtn!.trigger('click')
            expect(mockOpenWebsite).toHaveBeenCalledWith('https://example.com', 7)
        })
    })

    describe('favorites heart', () => {
        it('renders heart button', () => {
            const wrapper = mountCard()
            expect(wrapper.find('[data-testid="heart-icon"]').exists()).toBe(true)
        })

        it('shows "Save restaurant" aria-label when not favorited', () => {
            const wrapper = mountCard()
            const heartBtn = wrapper.find('[data-testid="heart-icon"]').element.closest('button')
            expect(heartBtn!.getAttribute('aria-label')).toBe('Save restaurant')
        })

        it('calls toggle on heart click', async () => {
            const wrapper = mountCard()
            const heartBtn = wrapper.find('[aria-label="Save restaurant"]')
            await heartBtn.trigger('click')
            expect(mockToggle).toHaveBeenCalled()
        })
    })

    describe('rank change', () => {
        it('leaves out the nationwide rank change, which means nothing in one city', () => {
            const wrapper = mountCard({ rank_change: 2082 })
            expect(wrapper.text()).not.toContain('2082')
            expect(wrapper.find('[data-testid="rank-change"]').exists()).toBe(false)
        })
    })

    describe('ScoreChip', () => {
        it('renders ScoreChip when popularity_score is set', () => {
            const wrapper = mountCard({ popularity_score: 85, score_breakdown: null })
            expect(wrapper.find('.score-chip-stub').exists()).toBe(true)
        })

        it('does not render ScoreChip when popularity_score is null', () => {
            const wrapper = mountCard({ popularity_score: null })
            expect(wrapper.find('.score-chip-stub').exists()).toBe(false)
        })
    })

    describe('link targets', () => {
        it('uses internal link when restaurant id > 0', () => {
            const wrapper = mountCard({ id: 123, slug: 'test-place' })
            const nameLink = wrapper.find('article').find('a')
            expect(nameLink.attributes('href')).toBe('/restaurants/test-place')
            expect(nameLink.attributes('target')).toBeUndefined()
        })

        it('uses external link when restaurant id <= 0', () => {
            const wrapper = mountCard({ id: 0, slug: 'live-result', name: 'Live Place' })
            const nameLink = wrapper.find('article').find('a')
            expect(nameLink.attributes('href')).toBe('/maps?q=Live Place')
            expect(nameLink.attributes('target')).toBe('_blank')
        })
    })

    describe('favorited state (saved class)', () => {
        it('does not have the brand color when not favorited', () => {
            const wrapper = mountCard()
            const heartBtn = wrapper.find('[data-testid="heart-icon"]').element.closest('button')!
            expect(heartBtn.classList.contains('text-primary')).toBe(false)
        })
    })

    describe('name links', () => {
        it('renders a link wrapping the restaurant name with the overlay class', () => {
            const wrapper = mountCard({ name: 'Overlay Test' })
            const overlayLink = wrapper.find('a.after\\:absolute')
            expect(overlayLink.exists()).toBe(true)
            expect(overlayLink.text()).toContain('Overlay Test')
        })
    })
})
