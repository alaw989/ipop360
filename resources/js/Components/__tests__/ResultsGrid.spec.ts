import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@lucide/vue', () => ({
    Utensils: { template: '<svg data-testid="utensils-icon" />' },
    X: { template: '<svg data-testid="x-icon" />' },
    Search: { template: '<svg data-testid="search-icon" />' },
}))

import ResultsGrid from '@/Components/ResultsGrid.vue'

function makeRestaurant(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        name: 'Test Restaurant',
        slug: 'test-restaurant',
        latitude: 40.7128,
        longitude: -74.006,
        ...overrides,
    } as any
}

function mountGrid(overrides: Record<string, unknown> = {}) {
    const props = {
        phase: 'results',
        restaurants: [],
        resultCount: 0,
        sort: 'quality',
        sortOptions: [
            { value: 'quality', label: 'Quality' },
            { value: 'distance', label: 'Nearest' },
        ],
        nextPageUrl: null,
        searchError: null,
        loadMoreError: null,
        lat: null,
        lng: null,
        selectedCuisine: undefined,
        shouldStagger: false,
        isResorting: false,
        ...overrides,
    }
    return mount(ResultsGrid, {
        props,
        global: {
            stubs: {
                Badge: { template: '<span class="badge" :class="variant"><slot /></span>', props: ['variant'] },
                Button: { template: '<button :class="variant" :aria-label="ariaLabel"><slot /></button>', props: ['variant', 'size', 'ariaLabel'] },
                Card: { template: '<div class="card"><slot /></div>' },
                CardContent: { template: '<div class="card-content"><slot /></div>' },
                RestaurantCard: { template: '<div class="restaurant-card-stub" :data-rank="rank">{{ restaurant?.name }}</div>', props: ['restaurant', 'rank', 'searchLat', 'searchLng', 'cuisine', 'stagger'] },
            },
        },
    })
}

describe('ResultsGrid', () => {
    describe('searching phase', () => {
        it('renders the loading spinner and message', () => {
            const wrapper = mountGrid({ phase: 'searching' })
            expect(wrapper.text()).toContain('Finding the best spots...')
            expect(wrapper.find('.animate-spin').exists()).toBe(true)
        })
    })

    describe('error phase', () => {
        it('renders the error message', () => {
            const wrapper = mountGrid({ phase: 'error', searchError: 'Network timeout' })
            expect(wrapper.text()).toContain('Network timeout')
            expect(wrapper.text()).toContain('Search Error')
        })

        it('renders Start Over and Try Again buttons', () => {
            const wrapper = mountGrid({ phase: 'error' })
            expect(wrapper.text()).toContain('Start Over')
            expect(wrapper.text()).toContain('Try Again')
        })

        it('emits resetToIdle when Start Over is clicked', () => {
            const wrapper = mountGrid({ phase: 'error' })
            const startOver = wrapper.findAll('button').find(b => b.text() === 'Start Over')
            startOver!.trigger('click')
            expect(wrapper.emitted('resetToIdle')).toBeTruthy()
        })

        it('emits search when Try Again is clicked', () => {
            const wrapper = mountGrid({ phase: 'error' })
            const tryAgain = wrapper.findAll('button').find(b => b.text() === 'Try Again')
            tryAgain!.trigger('click')
            expect(wrapper.emitted('search')).toBeTruthy()
        })
    })

    describe('empty phase', () => {
        it('renders the empty state message', () => {
            const wrapper = mountGrid({ phase: 'empty' })
            expect(wrapper.text()).toContain('No restaurants found')
            expect(wrapper.text()).toContain('Try a different cuisine or location.')
        })

        it('renders the utensils icon', () => {
            const wrapper = mountGrid({ phase: 'empty' })
            expect(wrapper.find('[data-testid="utensils-icon"]').exists()).toBe(true)
        })

        it('emits resetToIdle when Start Over is clicked', () => {
            const wrapper = mountGrid({ phase: 'empty' })
            const startOver = wrapper.findAll('button').find(b => b.text() === 'Start Over')
            startOver!.trigger('click')
            expect(wrapper.emitted('resetToIdle')).toBeTruthy()
        })
    })

    describe('results phase', () => {
        it('renders result count with singular label for 1 result', () => {
            const wrapper = mountGrid({ resultCount: 1 })
            expect(wrapper.text()).toContain('1 result')
            expect(wrapper.text()).not.toContain('1 results')
        })

        it('renders result count with plural label for multiple results', () => {
            const wrapper = mountGrid({ resultCount: 5 })
            expect(wrapper.text()).toContain('5 results')
        })

        it('renders sort dropdown with options', () => {
            const wrapper = mountGrid()
            const select = wrapper.find('select')
            expect(select.exists()).toBe(true)
            const options = select.findAll('option')
            expect(options).toHaveLength(2)
            expect(options[0].text()).toBe('Quality')
            expect(options[1].text()).toBe('Nearest')
        })

        it('sets the select value from the sort prop', () => {
            const wrapper = mountGrid({ sort: 'distance' })
            const select = wrapper.find('select')
            expect((select.element as HTMLSelectElement).value).toBe('distance')
        })

        it('emits update:sort and resort on sort change', async () => {
            const wrapper = mountGrid({ sort: 'quality' })
            const select = wrapper.find('select')
            await select.setValue('distance')
            expect(wrapper.emitted('update:sort')).toBeTruthy()
            expect(wrapper.emitted('update:sort')![0]).toEqual(['distance'])
            expect(wrapper.emitted('resort')).toBeTruthy()
        })

        it('renders RestaurantCard for each restaurant with correct props', () => {
            const restaurants = [
                makeRestaurant({ id: 1, name: 'Pizza Place' }),
                makeRestaurant({ id: 2, name: 'Sushi Bar' }),
            ]
            const wrapper = mountGrid({ restaurants, lat: 40.7, lng: -74.0, selectedCuisine: 'italian' })
            const cards = wrapper.findAll('.restaurant-card-stub')
            expect(cards).toHaveLength(2)
            expect(cards[0].attributes('data-rank')).toBe('1')
            expect(cards[1].attributes('data-rank')).toBe('2')
        })

        it('does not render load more button when nextPageUrl is null', () => {
            const wrapper = mountGrid({ nextPageUrl: null })
            expect(wrapper.text()).not.toContain('Load More')
        })

        it('renders load more button when nextPageUrl is set', () => {
            const wrapper = mountGrid({ nextPageUrl: '/api/restaurants?page=2' })
            expect(wrapper.text()).toContain('Load More')
        })

        it('emits loadMore when load more button is clicked', () => {
            const wrapper = mountGrid({ nextPageUrl: '/api/restaurants?page=2' })
            const loadMore = wrapper.findAll('button').find(b => b.text() === 'Load More')
            loadMore!.trigger('click')
            expect(wrapper.emitted('loadMore')).toBeTruthy()
        })

        it('applies opacity class when isResorting', () => {
            const wrapper = mountGrid({ isResorting: true })
            const grid = wrapper.find('.grid')
            expect(grid.classes()).toContain('opacity-40')
        })

        it('has full opacity when not resorting', () => {
            const wrapper = mountGrid({ isResorting: false })
            const grid = wrapper.find('.grid')
            expect(grid.classes()).toContain('opacity-100')
        })
    })

    describe('load more error', () => {
        it('renders the load-more error card with message', () => {
            const wrapper = mountGrid({ loadMoreError: 'Rate limit exceeded', nextPageUrl: '/api/restaurants?page=2' })
            expect(wrapper.text()).toContain('Load Error')
            expect(wrapper.text()).toContain('Rate limit exceeded')
        })

        it('renders Retry button in load-more error card', () => {
            const wrapper = mountGrid({ loadMoreError: 'Error', nextPageUrl: '/api/restaurants?page=2' })
            expect(wrapper.text()).toContain('Retry')
        })

        it('emits loadMore when Retry button is clicked', () => {
            const wrapper = mountGrid({ loadMoreError: 'Error', nextPageUrl: null })
            const retry = wrapper.findAll('button').find(b => b.text() === 'Retry')
            retry!.trigger('click')
            expect(wrapper.emitted('loadMore')).toBeTruthy()
        })

        it('renders dismiss button and emits dismissLoadMoreError when clicked', () => {
            const wrapper = mountGrid({ loadMoreError: 'Error', nextPageUrl: '/api/restaurants?page=2' })
            const dismiss = wrapper.find('button[aria-label="Dismiss"]')
            expect(dismiss.exists()).toBe(true)
            dismiss.trigger('click')
            expect(wrapper.emitted('dismissLoadMoreError')).toBeTruthy()
        })
    })
})
