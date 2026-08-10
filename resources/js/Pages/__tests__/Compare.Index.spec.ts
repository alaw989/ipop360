import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import CompareIndex from '@/Pages/Compare/Index.vue'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Head: { template: '<div />' },
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

vi.mock('@lucide/vue', () => ({
    ArrowLeft: { template: '<svg data-testid="arrow-left" />' },
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
        score_breakdown: null,
        source: null,
        ...overrides,
    }
}

function mountCompare(propsOverrides: Record<string, any> = {}) {
    return mount(CompareIndex, {
        props: {
            restaurants: [makeRestaurant()],
            ...propsOverrides,
        },
        global: { stubs },
    })
}

describe('Compare Index page', () => {
    it('renders the page heading', () => {
        const wrapper = mountCompare()
        expect(wrapper.text()).toContain('Compare Restaurants')
    })

    it('renders back link to browse page', () => {
        const wrapper = mountCompare()
        const link = wrapper.find('a[href="/restaurants"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('Back')
    })

    it('shows subtitle with restaurant count', () => {
        const wrapper = mountCompare({
            restaurants: [
                makeRestaurant({ id: 1, name: 'A', slug: 'a' }),
                makeRestaurant({ id: 2, name: 'B', slug: 'b' }),
            ],
        })
        expect(wrapper.text()).toContain('Side-by-side comparison of 2 restaurants')
    })

    it('shows empty subtitle with browse link when no items', () => {
        const wrapper = mountCompare({ restaurants: [] })
        expect(wrapper.text()).toContain('No restaurants selected')
        expect(wrapper.text()).toContain('browse page')
    })

    it('renders empty state with emoji and message when no items', () => {
        const wrapper = mountCompare({ restaurants: [] })
        expect(wrapper.text()).toContain('🔍')
        expect(wrapper.text()).toContain('Select restaurants from the browse page to compare them.')
    })

    it('renders restaurant names as links to detail page', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ id: 1, name: "Joe's Pizza", slug: 'joes-pizza' })],
        })
        const link = wrapper.find('a[href="/restaurants/joes-pizza"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain("Joe's Pizza")
    })

    it('renders multiple restaurant cards', () => {
        const wrapper = mountCompare({
            restaurants: [
                makeRestaurant({ id: 1, name: 'First Place', slug: 'first-place' }),
                makeRestaurant({ id: 2, name: 'Second Place', slug: 'second-place' }),
            ],
        })
        expect(wrapper.text()).toContain('First Place')
        expect(wrapper.text()).toContain('Second Place')
    })

    it('shows photo when photo_url is present', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ photo_url: 'https://example.com/photo.jpg' })],
        })
        const img = wrapper.find('img')
        expect(img.exists()).toBe(true)
        expect(img.attributes('src')).toBe('https://example.com/photo.jpg')
    })

    it('shows placeholder emoji when no photo', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ photo_url: null })],
        })
        expect(wrapper.text()).toContain('🍽')
    })

    it('shows city and state', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ city: 'Portland', state: 'OR' })],
        })
        expect(wrapper.text()).toContain('Portland, OR')
    })

    it('shows ScoreChip for each restaurant', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ popularity_score: 92.3 })],
        })
        expect(wrapper.find('[data-testid="score-chip"]').exists()).toBe(true)
    })

    it('shows StarRating when restaurant has a rating', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ yelp_rating: 4.5, yelp_review_count: 120 })],
        })
        expect(wrapper.find('[data-testid="star-rating"]').exists()).toBe(true)
    })

    it('hides StarRating when restaurant has no rating', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ yelp_rating: null, google_rating: null })],
        })
        expect(wrapper.find('[data-testid="star-rating"]').exists()).toBe(false)
    })

    it('shows price range when present', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ price_range: '$$' })],
        })
        expect(wrapper.text()).toContain('$$')
    })

    it('shows dash for missing price range', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({ price_range: null })],
        })
        expect(wrapper.text()).toContain('—')
    })

    it('shows cuisines comma-separated', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({
                cuisines: [
                    { id: 1, name: 'Italian', slug: 'italian' },
                    { id: 2, name: 'Pizza', slug: 'pizza' },
                ],
            })],
        })
        expect(wrapper.text()).toContain('Italian, Pizza')
    })

    it('shows score breakdown table when signals have contribution data', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({
                score_breakdown: {
                    signals: [
                        { label: 'Quality', contribution: 0.3, weight: 0.35 },
                        { label: 'Proximity', contribution: 0.1, weight: 0.15 },
                    ],
                },
            })],
        })
        expect(wrapper.text()).toContain('Quality')
        expect(wrapper.text()).toContain('Proximity')
    })

    it('shows total score row in breakdown table', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({
                popularity_score: 0.875,
                score_breakdown: {
                    signals: [{ label: 'Quality', contribution: 0.3, weight: 0.35 }],
                },
            })],
        })
        expect(wrapper.text()).toContain('Total Score')
        expect(wrapper.text()).toContain('88%')
    })

    it('highlights max contribution in green', () => {
        const wrapper = mountCompare({
            restaurants: [
                makeRestaurant({
                    id: 1, name: 'High', slug: 'high',
                    score_breakdown: {
                        signals: [{ label: 'Quality', contribution: 0.4, weight: 0.35 }],
                    },
                }),
                makeRestaurant({
                    id: 2, name: 'Low', slug: 'low',
                    score_breakdown: {
                        signals: [{ label: 'Quality', contribution: 0.1, weight: 0.35 }],
                    },
                }),
            ],
        })
        const greenCells = wrapper.findAll('.text-green-600')
        expect(greenCells.length).toBeGreaterThanOrEqual(1)
    })

    it('shows "no breakdown" message when items exist but no score data', () => {
        const wrapper = mountCompare({
            restaurants: [
                makeRestaurant({ score_breakdown: null }),
                makeRestaurant({ id: 2, name: 'R2', slug: 'r2', score_breakdown: null }),
            ],
        })
        expect(wrapper.text()).toContain('Score breakdown data is not available')
    })

    it('renders ArrowLeft icon in back link', () => {
        const wrapper = mountCompare()
        expect(wrapper.find('[data-testid="arrow-left"]').exists()).toBe(true)
    })

    it('truncates long restaurant names in table header', () => {
        const wrapper = mountCompare({
            restaurants: [makeRestaurant({
                name: 'The Magnificent Restaurant of Extraordinary Dining',
                slug: 'long-name',
                score_breakdown: {
                    signals: [{ label: 'Quality', contribution: 0.3, weight: 0.35 }],
                },
            })],
        })
        const headerCells = wrapper.findAll('th')
        const nameCell = headerCells.find(th => th.text().includes('…'))
        expect(nameCell).toBeTruthy()
        expect((nameCell?.text() ?? '').length).toBeLessThan(50)
    })
})
