import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import LeaderboardIndex from '@/Pages/Leaderboard/Index.vue'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Head: { template: '<div />' },
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

vi.mock('@lucide/vue', () => ({
    ArrowUp: { template: '<svg data-testid="arrow-up" />' },
    ArrowDown: { template: '<svg data-testid="arrow-down" />' },
    Minus: { template: '<svg data-testid="minus" />' },
}))

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    ScoreChip: { template: '<div data-testid="score-chip">score chip</div>' },
    StarRating: { template: '<div data-testid="star-rating">star rating</div>' },
}

function makeRestaurant(overrides: Record<string, any> = {}) {
    return {
        id: 1,
        name: 'Test Restaurant',
        slug: 'test-restaurant',
        description: null,
        address: null,
        city: 'New York',
        state: 'NY',
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
        popularity_score: 85.5,
        rank_change: null,
        distance: null,
        cuisines: [],
        source: null,
        ...overrides,
    }
}

function mountLeaderboard(propsOverrides: Record<string, any> = {}) {
    return mount(LeaderboardIndex, {
        props: {
            restaurants: {
                data: [makeRestaurant()],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
            filters: {},
            ...propsOverrides,
        },
        global: { stubs },
    })
}

describe('Leaderboard Index page', () => {
    it('renders the page heading', () => {
        const wrapper = mountLeaderboard()
        expect(wrapper.text()).toContain('Restaurant Leaderboard')
    })

    it('renders the subtitle', () => {
        const wrapper = mountLeaderboard()
        expect(wrapper.text()).toContain('Top-ranked dining spots, ordered by popularity score.')
    })

    it('renders restaurant names as links to detail page', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ id: 1, name: 'Joe\'s Pizza', slug: 'joes-pizza' })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        const link = wrapper.find('a[href="/restaurants/joes-pizza"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain("Joe's Pizza")
    })

    it('renders multiple restaurants', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [
                    makeRestaurant({ id: 1, name: 'First Place', slug: 'first-place' }),
                    makeRestaurant({ id: 2, name: 'Second Place', slug: 'second-place' }),
                    makeRestaurant({ id: 3, name: 'Third Place', slug: 'third-place' }),
                ],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).toContain('First Place')
        expect(wrapper.text()).toContain('Second Place')
        expect(wrapper.text()).toContain('Third Place')
    })

    it('shows crown emoji for rank 1', () => {
        const wrapper = mountLeaderboard()
        expect(wrapper.text()).toContain('👑')
    })

    it('shows "#N" for ranks 2-3', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [
                    makeRestaurant({ id: 1, name: 'R1', slug: 'r1' }),
                    makeRestaurant({ id: 2, name: 'R2', slug: 'r2' }),
                    makeRestaurant({ id: 3, name: 'R3', slug: 'r3' }),
                ],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).toContain('#2')
        expect(wrapper.text()).toContain('#3')
    })

    it('shows numeric rank for rank 4+', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [
                    makeRestaurant({ id: 1, name: 'R1', slug: 'r1' }),
                    makeRestaurant({ id: 2, name: 'R2', slug: 'r2' }),
                    makeRestaurant({ id: 3, name: 'R3', slug: 'r3' }),
                    makeRestaurant({ id: 4, name: 'R4', slug: 'r4' }),
                ],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        const rankElements = wrapper.findAll('.tabular-nums')
        expect(rankElements.length).toBeGreaterThanOrEqual(1)
    })

    it('shows rank change arrow up when rank improved', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ rank_change: 5 })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.find('[data-testid="arrow-up"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('+5')
    })

    it('shows rank change arrow down when rank dropped', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ rank_change: -3 })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.find('[data-testid="arrow-down"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('-3')
    })

    it('shows rank change minus when rank unchanged', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ rank_change: 0 })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.find('[data-testid="minus"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('0')
    })

    it('hides rank change when rank_change is null', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ rank_change: null })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.find('[data-testid="arrow-up"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="arrow-down"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="minus"]').exists()).toBe(false)
    })

    it('shows award star when restaurant has award', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ has_award: true })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).toContain('⭐')
    })

    it('hides award star when restaurant has no award', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ has_award: false })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).not.toContain('⭐')
    })

    it('shows price range when present', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ price_range: '$$' })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).toContain('$$')
    })

    it('shows cuisines comma-separated', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({
                    cuisines: [
                        { id: 1, name: 'Italian', slug: 'italian' },
                        { id: 2, name: 'Pizza', slug: 'pizza' },
                    ],
                })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).toContain('Italian, Pizza')
    })

    it('shows city and state', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ city: 'Portland', state: 'OR' })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).toContain('Portland, OR')
    })

    it('shows ScoreChip for each restaurant', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ popularity_score: 92.3 })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.find('[data-testid="score-chip"]').exists()).toBe(true)
    })

    it('shows photo when photo_url is present', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ photo_url: 'https://example.com/photo.jpg' })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        const img = wrapper.find('img')
        expect(img.exists()).toBe(true)
        expect(img.attributes('src')).toBe('https://example.com/photo.jpg')
    })

    it('shows placeholder emoji when no photo', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant({ photo_url: null })],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).toContain('🍽')
    })

    it('does not show pagination when last_page is 1', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant()],
                current_page: 1,
                last_page: 1,
                next_page_url: null,
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).not.toContain('Previous')
        expect(wrapper.text()).not.toContain('Next')
    })

    it('shows pagination when last_page > 1', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant()],
                current_page: 2,
                last_page: 3,
                next_page_url: '/leaderboard?page=3',
                prev_page_url: '/leaderboard?page=1',
            },
        })
        expect(wrapper.text()).toContain('Previous')
        expect(wrapper.text()).toContain('Next')
        expect(wrapper.text()).toContain('Page 2 of 3')
    })

    it('hides Previous link on first page', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant()],
                current_page: 1,
                last_page: 3,
                next_page_url: '/leaderboard?page=2',
                prev_page_url: null,
            },
        })
        expect(wrapper.text()).not.toContain('Previous')
        expect(wrapper.text()).toContain('Next')
    })

    it('hides Next link on last page', () => {
        const wrapper = mountLeaderboard({
            restaurants: {
                data: [makeRestaurant()],
                current_page: 3,
                last_page: 3,
                next_page_url: null,
                prev_page_url: '/leaderboard?page=2',
            },
        })
        expect(wrapper.text()).not.toContain('Next')
        expect(wrapper.text()).toContain('Previous')
    })
})
