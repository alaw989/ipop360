import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import PopularRestaurants from '@/Components/PopularRestaurants.vue'

vi.mock('@/Components/StarRating.vue', () => ({
    default: {
        template: '<div class="star-rating-stub">{{ rating }} {{ source }}</div>',
        props: ['rating', 'source', 'reviewCount', 'size'],
    },
}))

vi.mock('@/Components/ScoreChip.vue', () => ({
    default: {
        template: '<span class="score-chip-stub">{{ total }}</span>',
        props: ['total', 'breakdown'],
    },
}))

vi.mock('@/Components/RestaurantCardSkeleton.vue', () => ({
    default: {
        template: '<div class="skeleton-stub" data-testid="skeleton" />',
    },
}))

vi.mock('@/lib/cuisine', () => ({
    cuisineGradient: (slug: string | null | undefined) =>
        slug ? `gradient-${slug}` : 'from-muted to-muted-foreground/20',
}))

function makeRestaurant(overrides: Partial<any> = {}) {
    return {
        id: 1,
        name: 'Test Place',
        slug: 'test-place',
        photo_url: null,
        city: 'Austin',
        state: 'TX',
        price_range: '$$',
        google_rating: null,
        google_review_count: 0,
        yelp_rating: null,
        yelp_review_count: 0,
        has_award: false,
        popularity_score: 0,
        score_breakdown: null,
        cuisines: [],
        ...overrides,
    }
}

function makeRestaurants(count: number) {
    return Array.from({ length: count }, (_, i) =>
        makeRestaurant({ id: i + 1, name: `Restaurant ${i + 1}`, slug: `restaurant-${i + 1}` }),
    )
}

function mountComponent(props: Partial<{
    restaurants: any[]
    city: string | null
    loading: boolean
}> = {}) {
    return mount(PopularRestaurants, {
        props: {
            restaurants: props.restaurants ?? makeRestaurants(5),
            city: props.city ?? null,
            loading: props.loading ?? false,
        },
    })
}

describe('PopularRestaurants', () => {
    beforeEach(() => {
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('renders the root section with expected heading', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('section').exists()).toBe(true)
        expect(wrapper.find('h2').text()).toContain('Trending restaurants')
    })

    it('renders the section as a muted full-width band', () => {
        const wrapper = mountComponent()
        const section = wrapper.find('section')
        expect(section.classes()).toContain('bg-muted/50')
        expect(section.classes()).toContain('w-full')
    })

    it('shows city in heading when city prop is provided', () => {
        const wrapper = mountComponent({ city: 'Austin' })
        expect(wrapper.find('h2').text()).toContain(' in Austin')
    })

    it('does not show city in heading when city is null', () => {
        const wrapper = mountComponent({ city: null })
        expect(wrapper.find('h2').text()).not.toContain(' in ')
    })

    it('renders the local subtitle text when city is provided', () => {
        const wrapper = mountComponent({ city: 'Austin' })
        expect(wrapper.text()).toContain('Top-ranked dining spots right now')
    })

    it('renders the fallback subtitle text when city is null', () => {
        const wrapper = mountComponent({ city: null })
        expect(wrapper.text()).toContain('Popular across iPop360')
        expect(wrapper.text()).not.toContain('Top-ranked dining spots right now')
    })

    it('renders 8 skeleton cards when loading is true', () => {
        const wrapper = mountComponent({ loading: true })
        expect(wrapper.findAll('[data-testid="skeleton"]')).toHaveLength(8)
        expect(wrapper.find('a[href^="/restaurants/"]').exists()).toBe(false)
    })

    it('does not render skeleton cards when loading is false', () => {
        const wrapper = mountComponent({ loading: false })
        expect(wrapper.findAll('[data-testid="skeleton"]')).toHaveLength(0)
    })

    it('renders the expected number of restaurant cards', () => {
        const restaurants = makeRestaurants(3)
        const wrapper = mountComponent({ restaurants })
        const links = wrapper.findAll('a[href^="/restaurants/"]')
        expect(links).toHaveLength(3)
    })

    it('renders restaurant names in cards', () => {
        const restaurants = [makeRestaurant({ id: 1, name: 'Freddie\'s BBQ', slug: 'freddies-bbq' })]
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.text()).toContain("Freddie's BBQ")
    })

    it('renders photo when photo_url is provided', () => {
        const restaurants = [makeRestaurant({ photo_url: '/img/photo.jpg', slug: 'test' })]
        const wrapper = mountComponent({ restaurants })
        const img = wrapper.find('img')
        expect(img.exists()).toBe(true)
        expect(img.attributes('src')).toBe('/img/photo.jpg')
        expect(img.attributes('alt')).toBe('Test Place')
    })

    it('renders gradient fallback when photo_url is null', () => {
        const restaurants = [makeRestaurant({ photo_url: null, slug: 'test' })]
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.text()).toContain('🍽')
    })

    it('renders rank badge with fire emoji for rank 1', () => {
        const restaurants = makeRestaurants(5)
        const wrapper = mountComponent({ restaurants })
        const badge = wrapper.find('.bg-gradient-to-r.from-amber-400.to-yellow-500')
        expect(badge.exists()).toBe(true)
        expect(badge.text()).toBe('🔥')
    })

    it('renders rank badge with #2 text for rank 2', () => {
        const restaurants = makeRestaurants(5)
        const wrapper = mountComponent({ restaurants })
        const yellowBadge = wrapper.find('.bg-gradient-to-r.from-slate-300.to-slate-400')
        expect(yellowBadge.exists()).toBe(true)
        expect(yellowBadge.text()).toBe('#2')
    })

    it('renders rank badge with #3 text for rank 3', () => {
        const restaurants = makeRestaurants(5)
        const wrapper = mountComponent({ restaurants })
        const orangeBadge = wrapper.find('.bg-gradient-to-r.from-orange-400.to-amber-600')
        expect(orangeBadge.exists()).toBe(true)
        expect(orangeBadge.text()).toBe('#3')
    })

    it('does not render rank badge for rank 4+', () => {
        const wrapper = mountComponent()
        const badges = wrapper.findAll('.bg-gradient-to-r')
        expect(badges).toHaveLength(3)
    })

    it('renders ScoreChip when popularity_score > 0', () => {
        const restaurants = [makeRestaurant({ popularity_score: 0.85, score_breakdown: null })]
        const wrapper = mountComponent({ restaurants })
        const chip = wrapper.find('.score-chip-stub')
        expect(chip.exists()).toBe(true)
        expect(chip.text()).toBe('0.85')
    })

    it('does not render ScoreChip when popularity_score is 0', () => {
        const restaurants = [makeRestaurant({ popularity_score: 0 })]
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.find('.score-chip-stub').exists()).toBe(false)
    })

    it('renders award star when has_award is true', () => {
        const restaurants = [makeRestaurant({ has_award: true })]
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.text()).toContain('⭐')
    })

    it('does not render award star when has_award is false', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).not.toContain('⭐')
    })

    it('renders StarRating when restaurant has yelp rating', () => {
        const restaurants = [makeRestaurant({ yelp_rating: 4.5, yelp_review_count: 120 })]
        const wrapper = mountComponent({ restaurants })
        const rating = wrapper.find('.star-rating-stub')
        expect(rating.exists()).toBe(true)
        expect(rating.text()).toContain('4.5')
        expect(rating.text()).toContain('Yelp')
    })

    it('renders StarRating when only google rating is available', () => {
        const restaurants = [makeRestaurant({ google_rating: 4.2, google_review_count: 50 })]
        const wrapper = mountComponent({ restaurants })
        const rating = wrapper.find('.star-rating-stub')
        expect(rating.exists()).toBe(true)
        expect(rating.text()).toContain('4.2')
        expect(rating.text()).toContain('Google')
    })

    it('prefers yelp rating over google when both are available', () => {
        const restaurants = [makeRestaurant({ yelp_rating: 4.5, google_rating: 4.2, yelp_review_count: 120 })]
        const wrapper = mountComponent({ restaurants })
        const rating = wrapper.find('.star-rating-stub')
        expect(rating.text()).toContain('Yelp')
        expect(rating.text()).not.toContain('Google')
    })

    it('does not render StarRating when no ratings exist', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('.star-rating-stub').exists()).toBe(false)
    })

    it('renders price range when present', () => {
        const restaurants = [makeRestaurant({ price_range: '$$' })]
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.text()).toContain('$$')
    })

    it('does not show bullet separator when price_range is null', () => {
        const restaurants = [makeRestaurant({ price_range: null, cuisines: [{ id: 1, name: 'Pizza', slug: 'pizza' }] })]
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.text()).not.toContain('•')
    })

    it('renders primary cuisine name', () => {
        const restaurants = [makeRestaurant({ cuisines: [{ id: 1, name: 'Italian', slug: 'italian' }] })]
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.text()).toContain('Italian')
    })

    it('shows "Show more" button when >12 restaurants and not expanded', () => {
        const restaurants = makeRestaurants(15)
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.text()).toContain('Show more')
        expect(wrapper.text()).not.toContain('Show less')
    })

    it('does not show toggle when restaurants are <=12', () => {
        const restaurants = makeRestaurants(12)
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.text()).not.toContain('Show more')
        expect(wrapper.text()).not.toContain('Show less')
    })

    it('toggles to "Show less" when "Show more" is clicked', async () => {
        const restaurants = makeRestaurants(15)
        const wrapper = mountComponent({ restaurants })

        const buttons = wrapper.findAll('button')
        const showMoreButton = buttons.find((b) => b.text() === 'Show more')
        expect(showMoreButton).toBeDefined()

        await showMoreButton!.trigger('click')
        expect(wrapper.text()).toContain('Show less')
        expect(wrapper.text()).not.toContain('Show more')
    })

    it('toggles chevron rotation when show more/less is clicked', async () => {
        const restaurants = makeRestaurants(15)
        const wrapper = mountComponent({ restaurants })

        const showMoreBtn = wrapper.find('button')
        expect(showMoreBtn.find('.rotate-180').exists()).toBe(false)

        await showMoreBtn.trigger('click')
        expect(wrapper.find('button').find('.rotate-180').exists()).toBe(true)
    })

    it('shows all restaurants when expanded', async () => {
        const restaurants = makeRestaurants(15)
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.findAll('a[href^="/restaurants/"]')).toHaveLength(12)

        await wrapper.find('button').trigger('click')
        expect(wrapper.findAll('a[href^="/restaurants/"]')).toHaveLength(15)
    })

    it('uses correct gradient class from cuisine slug', () => {
        const restaurants = [makeRestaurant({ photo_url: null, cuisines: [{ id: 3, name: 'Italian', slug: 'italian' }] })]
        const wrapper = mountComponent({ restaurants })
        const gradientDiv = wrapper.find('.gradient-italian')
        expect(gradientDiv.exists()).toBe(true)
    })

    it('limits visible cards to 12 when not expanded', () => {
        const restaurants = makeRestaurants(13)
        const wrapper = mountComponent({ restaurants })
        expect(wrapper.findAll('a[href^="/restaurants/"]')).toHaveLength(12)
    })

    it('renders each restaurant card as a link with correct href', () => {
        const restaurants = [makeRestaurant({ id: 42, slug: 'sluggy-slug' })]
        const wrapper = mountComponent({ restaurants })
        const link = wrapper.find('a[href="/restaurants/sluggy-slug"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('Test Place')
    })
})
