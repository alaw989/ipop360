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
let mockIsFavorited = (_r: any) => false

vi.mock('@/composables/useFavorites', () => ({
    useFavorites: () => ({
        isFavorited: (r: any) => mockIsFavorited(r),
        toggle: mockToggle,
    }),
}))

const mockToggleCompare = vi.fn()
let mockIsInCompare = (_r: any) => false

vi.mock('@/composables/useCompare', () => ({
    useCompare: () => ({
        isInCompare: (r: any) => mockIsInCompare(r),
        toggleCompare: mockToggleCompare,
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
    getRestaurantPhotos: (r: any) => r.photos ?? (r.photo_url ? [r.photo_url] : []),
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
    ArrowUp: { template: '<svg data-testid="arrow-up-icon" class="h-3 w-3" />' },
    ArrowDown: { template: '<svg data-testid="arrow-down-icon" class="h-3 w-3" />' },
    Minus: { template: '<svg data-testid="minus-icon" class="h-3 w-3" />' },
}))

import RestaurantCard from '@/Components/RestaurantCard.vue'

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
    return mount(RestaurantCard, {
        props: {
            restaurant: makeRestaurant(restaurant),
            rank: 1,
            searchLat: null,
            searchLng: null,
            ...extraProps,
        },
        global: {
            stubs: {
                CardGallery: { template: '<div class="card-gallery-stub"><slot name="overlays" /></div>' },
                StarRating: { template: '<div class="star-rating-stub"><span data-testid="star-rating">{{ rating }}</span></div>', props: ['rating', 'source', 'reviewCount', 'size'] },
                ScoreChip: { template: '<div class="score-chip-stub" :data-total="total"><slot /></div>', props: ['total', 'breakdown'] },
                Badge: { template: '<span class="badge" :class="variant">{{ variant }} <slot /></span>', props: ['variant'] },
            },
        },
    })
}

describe('RestaurantCard', () => {
    beforeEach(() => {
        mockCallPhone.mockClear()
        mockOpenWebsite.mockClear()
        mockTrackDirections.mockClear()
        mockToggle.mockClear()
        mockToggleCompare.mockClear()
        mockIsFavorited = (_r: any) => false
        mockIsInCompare = (_r: any) => false
    })

    describe('basic rendering', () => {
        it('renders the restaurant name', () => {
            const wrapper = mountCard({ name: 'Pizza Palace' })
            expect(wrapper.text()).toContain('Pizza Palace')
        })

        it('renders an article element', () => {
            const wrapper = mountCard()
            expect(wrapper.find('article').exists()).toBe(true)
        })

        it('renders address when present', () => {
            const wrapper = mountCard({ address: '456 Elm St', city: 'Boston', state: null })
            expect(wrapper.text()).toContain('456 Elm St')
        })

        it('renders city and state when no address', () => {
            const wrapper = mountCard({ address: null, city: 'Chicago', state: 'IL' })
            expect(wrapper.text()).toContain('Chicago, IL')
        })

        it('renders city only when state is null and no address', () => {
            const wrapper = mountCard({ address: null, city: 'Austin', state: null })
            expect(wrapper.text()).toContain('Austin')
        })
    })

    describe('rank badge', () => {
        it('renders fire emoji for rank 1', () => {
            const wrapper = mountCard({}, { rank: 1 })
            expect(wrapper.text()).toContain('🔥')
            expect(wrapper.text()).not.toContain('#1')
        })

        it('renders #2 for rank 2', () => {
            const wrapper = mountCard({}, { rank: 2 })
            expect(wrapper.text()).toContain('#2')
        })

        it('renders #N for rank > 1', () => {
            const wrapper = mountCard({}, { rank: 5 })
            expect(wrapper.text()).toContain('#5')
        })
    })

    describe('rank change indicator', () => {
        it('renders ArrowUp for positive rank change', () => {
            const wrapper = mountCard({ rank_change: 3 })
            expect(wrapper.find('[data-testid="arrow-up-icon"]').exists()).toBe(true)
        })

        it('renders ArrowDown for negative rank change', () => {
            const wrapper = mountCard({ rank_change: -2 })
            expect(wrapper.find('[data-testid="arrow-down-icon"]').exists()).toBe(true)
        })

        it('renders Minus for zero rank change', () => {
            const wrapper = mountCard({ rank_change: 0 })
            expect(wrapper.find('[data-testid="minus-icon"]').exists()).toBe(true)
        })

        it('does not render rank change when null', () => {
            const wrapper = mountCard({ rank_change: null })
            expect(wrapper.find('[data-testid="arrow-up-icon"]').exists()).toBe(false)
            expect(wrapper.find('[data-testid="arrow-down-icon"]').exists()).toBe(false)
            expect(wrapper.find('[data-testid="minus-icon"]').exists()).toBe(false)
        })

        it('sets correct title for positive change', () => {
            const wrapper = mountCard({ rank_change: 5 })
            const indicator = wrapper.find('[data-testid="arrow-up-icon"]').element.parentElement!
            expect(indicator.getAttribute('title')).toBe('Up 5 spots')
        })

        it('sets correct title for negative change', () => {
            const wrapper = mountCard({ rank_change: -3 })
            const indicator = wrapper.find('[data-testid="arrow-down-icon"]').element.parentElement!
            expect(indicator.getAttribute('title')).toBe('Down 3 spots')
        })

        it('sets Steady title for zero change', () => {
            const wrapper = mountCard({ rank_change: 0 })
            const indicator = wrapper.find('[data-testid="minus-icon"]').element.parentElement!
            expect(indicator.getAttribute('title')).toBe('Steady')
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
            expect(wrapper.text()).not.toContain('mi')
        })
    })

    describe('description', () => {
        it('renders description when present', () => {
            const wrapper = mountCard({ description: 'Amazing food and great service!' })
            expect(wrapper.text()).toContain('Amazing food and great service!')
        })

        it('does not render description when null', () => {
            const wrapper = mountCard({ description: null })
            expect(wrapper.text()).not.toContain('Amazing food')
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

        it('does not render badges when no cuisines', () => {
            const wrapper = mountCard({ cuisines: [] })
            expect(wrapper.findAll('.badge').length).toBe(0)
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
            const dirsLink = wrapper.findAll('a').find(a => a.text() === 'Directions')
            await dirsLink!.trigger('click')
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

        it('shows "Saved" aria-label when favorited', () => {
            mockIsFavorited = () => true
            const wrapper = mountCard()
            const heartBtn = wrapper.find('[data-testid="heart-icon"]').element.closest('button')
            expect(heartBtn!.getAttribute('aria-label')).toBe('Saved')
        })

        it('calls toggle on heart click', async () => {
            const wrapper = mountCard()
            const heartBtn = wrapper.find('[aria-label="Save restaurant"]')
            await heartBtn.trigger('click')
            expect(mockToggle).toHaveBeenCalled()
        })

        it('has text-red-500 class when favorited', () => {
            mockIsFavorited = () => true
            const wrapper = mountCard()
            const heartBtn = wrapper.find('[data-testid="heart-icon"]').element.closest('button')!
            expect(heartBtn.classList.contains('text-red-500')).toBe(true)
        })

        it('does not have text-red-500 class when not favorited', () => {
            mockIsFavorited = () => false
            const wrapper = mountCard()
            const heartBtn = wrapper.find('[data-testid="heart-icon"]').element.closest('button')!
            expect(heartBtn.classList.contains('text-red-500')).toBe(false)
        })
    })

    describe('compare button', () => {
        it('renders compare button', () => {
            const wrapper = mountCard()
            const compareBtn = wrapper.findAll('button').find(b => b.attributes('title') === 'Add to comparison')
            expect(compareBtn!.exists()).toBe(true)
        })

        it('shows "Remove from comparison" title when in compare', () => {
            mockIsInCompare = () => true
            const wrapper = mountCard()
            const compareBtn = wrapper.findAll('button').find(b => b.attributes('title') === 'Remove from comparison')
            expect(compareBtn!.exists()).toBe(true)
        })

        it('calls toggleCompare on compare button click', async () => {
            const wrapper = mountCard({ id: 99 })
            const compareBtn = wrapper.findAll('button').find(b => b.attributes('title') === 'Add to comparison')
            await compareBtn!.trigger('click')
            expect(mockToggleCompare).toHaveBeenCalled()
        })
    })

    describe('name overlay link', () => {
        it('renders overlay link wrapping the restaurant name', () => {
            const wrapper = mountCard({ name: 'Overlay Test' })
            const overlayLink = wrapper.find('a.after\\:absolute')
            expect(overlayLink.exists()).toBe(true)
            expect(overlayLink.text()).toBe('Overlay Test')
        })

        it('uses internal link when restaurant id > 0', () => {
            const wrapper = mountCard({ id: 123, slug: 'test-place' })
            const nameLink = wrapper.find('article').find('a')
            expect(nameLink.attributes('href')).toBe('/restaurants/test-place')
            expect(nameLink.attributes('target')).toBeUndefined()
        })

        it('uses external _blank link when id <= 0', () => {
            const wrapper = mountCard({ id: 0, slug: 'live-result', name: 'Live Place' })
            const nameLink = wrapper.find('article').find('a')
            expect(nameLink.attributes('href')).toBe('/maps?q=Live Place')
            expect(nameLink.attributes('target')).toBe('_blank')
        })
    })

    describe('stagger animation', () => {
        it('adds card-enter class when stagger is true and rank <= 12', () => {
            const wrapper = mountCard({}, { stagger: true, rank: 5 })
            const article = wrapper.find('article')
            expect(article.classes()).toContain('card-enter')
        })

        it('does not add card-enter class when stagger is true but rank > 12', () => {
            const wrapper = mountCard({}, { stagger: true, rank: 15 })
            const article = wrapper.find('article')
            expect(article.classes()).not.toContain('card-enter')
        })

        it('does not add card-enter class when stagger is false', () => {
            const wrapper = mountCard({}, { stagger: false, rank: 5 })
            const article = wrapper.find('article')
            expect(article.classes()).not.toContain('card-enter')
        })

        it('does not add card-enter class when stagger is undefined', () => {
            const wrapper = mountCard({}, { stagger: undefined, rank: 5 })
            const article = wrapper.find('article')
            expect(article.classes()).not.toContain('card-enter')
        })
    })

    describe('photo gallery', () => {
        it('passes photos to CardGallery from photo_url', () => {
            const wrapper = mountCard({ photo_url: 'https://example.com/photo.jpg' })
            expect(wrapper.find('.card-gallery-stub').exists()).toBe(true)
        })

        it('passes gradient to CardGallery', () => {
            const wrapper = mountCard()
            expect(wrapper.find('.card-gallery-stub').exists()).toBe(true)
        })

        it('renders alt text on CardGallery', () => {
            const wrapper = mountCard({ name: 'Gallery Test' })
            expect(wrapper.find('.card-gallery-stub').exists()).toBe(true)
        })
    })

    describe('Directions link', () => {
        it('renders correct Google Maps href', () => {
            const wrapper = mountCard({ lat: 40.7128, lng: -74.006 })
            const dirsLink = wrapper.findAll('a').find(a => a.text() === 'Directions')
            expect(dirsLink!.attributes('href')).toBe('https://www.google.com/maps/dir/?api=1&destination=40.7128,-74.006')
        })

        it('has target _blank and rel noopener', () => {
            const wrapper = mountCard({ lat: 40.7128, lng: -74.006 })
            const dirsLink = wrapper.findAll('a').find(a => a.text() === 'Directions')
            expect(dirsLink!.attributes('target')).toBe('_blank')
            expect(dirsLink!.attributes('rel')).toBe('noopener')
        })
    })

    describe('CV card classes', () => {
        it('has cv-card class on the article', () => {
            const wrapper = mountCard()
            expect(wrapper.find('article.cv-card').exists()).toBe(true)
        })

        it('has group class for hover interactions', () => {
            const wrapper = mountCard()
            expect(wrapper.find('article').classes()).toContain('group')
        })
    })
})
