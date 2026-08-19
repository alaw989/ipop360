import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import Search from '@/Pages/Search.vue'
import { router } from '@inertiajs/vue3'
import { useSeo } from '@/composables/useSeo'

const mockSerpapiExhausted = vi.hoisted(() => ({ value: false }))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        router: {
            get: vi.fn(),
            on: vi.fn(),
        },
        usePage: () => ({ props: { serpapi_exhausted: mockSerpapiExhausted.value } }),
        Link: { template: '<a><slot /></a>' },
    }
})

vi.mock('@/composables/useSeo', () => ({
    useSeo: vi.fn((options: Record<string, unknown>) => options),
    generateItemListJsonLd: vi.fn(() => ({ '@type': 'ItemList', itemListElement: [] })),
}))

const mockedUseSeo = vi.mocked(useSeo)

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SeoMeta: { template: '<div />' },
    JsonLd: { template: '<div />' },
    SearchFilters: { template: '<div />' },
    SearchResultCard: { template: '<div />' },
    SearchMap: { template: '<div />' },
    Sheet: { template: '<div><slot /></div>' },
    SheetTrigger: { template: '<div><slot /></div>' },
    SheetContent: { template: '<div data-testid="filter-sheet"><slot /></div>' },
    SheetTitle: { template: '<h2 data-testid="sheet-title"><slot /></h2>' },
    SheetDescription: { template: '<p data-testid="sheet-description"><slot /></p>' },
}

const baseProps = {
    restaurants: { data: [], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
    filters: {},
    cuisineName: null,
    categorySlug: null,
    filterOptions: {
        categories: [],
        cuisines: [],
        priceOptions: ['$', '$$', '$$$', '$$$$'],
        distanceOptions: [1, 5, 10, 25, 50],
    },
    hasCoords: false,
}

function mountSearch(props = {}) {
    return mount(Search, {
        props: { ...baseProps, ...props },
        global: { stubs },
    })
}

describe('Search location banner', () => {
    beforeEach(() => {
        localStorage.clear()
        mockSerpapiExhausted.value = false
    })

    it('shows location banner when hasCoords is false and distance filter is set', () => {
        const wrapper = mountSearch({ filters: { distance: '10' } })
        expect(wrapper.text()).toContain('Enable location sharing')
    })

    it('hides location banner when hasCoords is true', () => {
        const wrapper = mountSearch({ hasCoords: true, filters: { distance: '10' } })
        expect(wrapper.text()).not.toContain('Enable location sharing')
    })

    it('hides location banner when distance filter is not set', () => {
        const wrapper = mountSearch()
        expect(wrapper.text()).not.toContain('Enable location sharing')
    })

    it('dismisses location banner on button click', async () => {
        const wrapper = mountSearch({ filters: { distance: '10' } })
        expect(wrapper.text()).toContain('Enable location sharing')
        const dismissBtn = wrapper.findAll('button').find((b) => b.text().trim() === 'Dismiss')
        await dismissBtn!.trigger('click')
        expect(wrapper.text()).not.toContain('Enable location sharing')
        expect(localStorage.getItem('dismissedLocationBanner')).toBe('1')
    })

    it('hides location banner when previously dismissed', () => {
        localStorage.setItem('dismissedLocationBanner', '1')
        const wrapper = mountSearch({ filters: { distance: '10' } })
        expect(wrapper.text()).not.toContain('Enable location sharing')
    })
})

describe('Search handleFilterChange', () => {
    it('strips undefined values before routing', () => {
        const wrapper = mountSearch({ filters: { cuisine: 'chinese', distance: '10' } })
        const vm = wrapper.vm as any
        vm.handleFilterChange({ distance: undefined })
        expect(router.get).toHaveBeenCalledWith(
            '/search',
            { cuisine: 'chinese' },
            expect.objectContaining({ preserveState: true, replace: true }),
        )
    })
})

describe('Search mobile filter sheet', () => {
    it('renders a mobile filter toggle button', () => {
        const wrapper = mountSearch()
        expect(wrapper.find('[data-testid="mobile-filter-toggle"]').exists()).toBe(true)
    })

    it('hides the filter toggle on desktop (lg:hidden)', () => {
        const wrapper = mountSearch()
        expect(wrapper.find('[data-testid="mobile-filter-toggle"]').classes()).toContain('lg:hidden')
    })

    it('renders the filter sheet content', () => {
        const wrapper = mountSearch()
        expect(wrapper.find('[data-testid="filter-sheet"]').exists()).toBe(true)
    })

    it('renders an accessible title and description in the filter sheet', () => {
        const wrapper = mountSearch()
        expect(wrapper.find('[data-testid="sheet-title"]').text()).toBe('Filters')
        expect(wrapper.find('[data-testid="sheet-description"]').exists()).toBe(true)
    })

    it('opens the filter sheet when the toggle is clicked', async () => {
        const wrapper = mountSearch()
        await wrapper.find('[data-testid="mobile-filter-toggle"]').trigger('click')
        expect(wrapper.find('[data-testid="filter-sheet"]').exists()).toBe(true)
    })
})

describe('Search mobile map toggle', () => {
    it('renders a mobile map toggle button', () => {
        const wrapper = mountSearch()
        expect(wrapper.find('[data-testid="mobile-map-toggle"]').exists()).toBe(true)
    })

    it('hides the map toggle on desktop (xl:hidden)', () => {
        const wrapper = mountSearch()
        expect(wrapper.find('[data-testid="mobile-map-toggle"]').classes()).toContain('xl:hidden')
    })

    it('shows the results list by default (map view hidden)', () => {
        const wrapper = mountSearch()
        expect(wrapper.find('[data-testid="mobile-map"]').exists()).toBe(false)
    })

    it('shows the map view when toggled', async () => {
        const wrapper = mountSearch()
        await wrapper.find('[data-testid="mobile-map-toggle"]').trigger('click')
        expect(wrapper.find('[data-testid="mobile-map"]').exists()).toBe(true)
    })

    it('returns to the list view when toggled again', async () => {
        const wrapper = mountSearch()
        const toggle = wrapper.find('[data-testid="mobile-map-toggle"]')
        await toggle.trigger('click')
        expect(wrapper.find('[data-testid="mobile-map"]').exists()).toBe(true)
        await toggle.trigger('click')
        expect(wrapper.find('[data-testid="mobile-map"]').exists()).toBe(false)
    })
})

describe('Search rating sort relabel', () => {
    beforeEach(() => {
        mockSerpapiExhausted.value = false
    })

    it('shows "Rating" when SerpApi provider is not exhausted', () => {
        const wrapper = mountSearch()
        const rating = wrapper.findAll('#search-sort option').find((o) => o.attributes('value') === 'rating')
        expect(rating!.text()).toBe('Rating')
    })

    it('relabels Rating option when SerpApi provider is exhausted', () => {
        mockSerpapiExhausted.value = true
        const wrapper = mountSearch()
        const rating = wrapper.findAll('#search-sort option').find((o) => o.attributes('value') === 'rating')
        expect(rating!.text()).toBe('Ratings temporarily unavailable')
    })
})

describe('Search SEO copy', () => {
    beforeEach(() => {
        mockSerpapiExhausted.value = false
        mockedUseSeo.mockClear()
    })

    it('mentions rating in description when SerpApi is available', () => {
        mountSearch({ cuisineName: 'Italian' })
        expect(mockedUseSeo).toHaveBeenCalledWith(expect.objectContaining({
            description: expect.stringContaining('rating'),
        }))
    })

    it('drops rating phrasing when SerpApi is exhausted', () => {
        mockSerpapiExhausted.value = true
        mountSearch({ cuisineName: 'Italian' })
        const last = mockedUseSeo.mock.calls.at(-1)![0] as { description: string }
        expect(last.description).not.toMatch(/rating/i)
    })
})
