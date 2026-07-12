import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import Search from '@/Pages/Search.vue'
import { router } from '@inertiajs/vue3'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        router: {
            get: vi.fn(),
            on: vi.fn(),
        },
        Link: { template: '<a><slot /></a>' },
    }
})

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SeoMeta: { template: '<div />' },
    JsonLd: { template: '<div />' },
    SearchFilters: { template: '<div />' },
    SearchResultCard: { template: '<div />' },
    SearchMap: { template: '<div />' },
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
        await wrapper.find('button').trigger('click')
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
