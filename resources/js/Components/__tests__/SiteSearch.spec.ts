import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const { mockPage, mockGet, begin, end } = vi.hoisted(() => ({
    mockPage: { props: {} as Record<string, unknown>, url: '/' },
    mockGet: vi.fn(),
    begin: vi.fn(),
    end: vi.fn(),
}))

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => mockPage,
    router: { get: mockGet },
}))

vi.mock('@/composables/useSearchLoadingOverlay', () => ({
    useSearchLoadingOverlay: () => ({ begin, end }),
}))

vi.mock('@/Components/CuisinePicker.vue', async () => {
    const { defineComponent, h } = await import('vue')
    return {
        default: defineComponent({
            name: 'CuisinePicker',
            props: ['categories', 'loading', 'initialLabel', 'variant', 'size'],
            emits: ['select'],
            setup(props) {
                return () => h('div', { 'data-testid': 'cuisine', 'data-label': props.initialLabel ?? '', 'data-count': String(props.categories?.length ?? 0) })
            },
        }),
    }
})

vi.mock('@/Components/LocationPicker.vue', async () => {
    const { defineComponent, h } = await import('vue')
    return {
        default: defineComponent({
            name: 'LocationPicker',
            props: ['location', 'detecting', 'placeholder', 'variant', 'size'],
            emits: ['update', 'coords', 'detect'],
            setup(props) {
                return () => h('div', { 'data-testid': 'location', 'data-placeholder': props.placeholder })
            },
        }),
    }
})

import SiteSearch from '@/Components/SiteSearch.vue'

function lastVisit() {
    const call = mockGet.mock.calls.at(-1)
    return { url: call?.[0], params: call?.[1] as Record<string, unknown> }
}

describe('SiteSearch', () => {
    beforeEach(() => {
        mockGet.mockReset()
        begin.mockReset()
        mockPage.props = {}
        mockPage.url = '/'
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve([{ id: 1, name: 'Asian', slug: 'asian', icon: null, cuisines: [] }]),
        })))
    })

    it('loads the cuisine list for the What field', async () => {
        const wrapper = mount(SiteSearch)
        await flushPromises()
        expect(wrapper.get('[data-testid="cuisine"]').attributes('data-count')).toBe('1')
    })

    it('searches the picked cuisine in the picked city', async () => {
        const wrapper = mount(SiteSearch)
        wrapper.findComponent({ name: 'CuisinePicker' }).vm.$emit('select', { category: 'asian', cuisine: 'ramen', label: 'Asian ▸ Ramen' })
        wrapper.findComponent({ name: 'LocationPicker' }).vm.$emit('update', { city: 'Austin', state: 'TX' })
        wrapper.findComponent({ name: 'LocationPicker' }).vm.$emit('coords', 30.27, -97.74)
        await wrapper.get('form').trigger('submit')

        const { url, params } = lastVisit()
        expect(url).toBe('/search')
        expect(params).toMatchObject({ cuisine: 'ramen', category: 'asian', lat: '30.27', lng: '-97.74', sort: 'best_match' })
        expect(begin).toHaveBeenCalled()
        expect(wrapper.emitted('searched')).toHaveLength(1)
    })

    it('keeps the area of the results on screen when no place is picked', async () => {
        mockPage.url = '/search?lat=30.2672&lng=-97.7431&distance=25'
        mockPage.props = { filters: { cuisine: 'tacos', lat: '30.2672', lng: '-97.7431' }, cuisineName: 'Tacos' }
        const wrapper = mount(SiteSearch)

        expect(wrapper.get('[data-testid="location"]').attributes('data-placeholder')).toBe('This area')
        expect(wrapper.get('[data-testid="cuisine"]').attributes('data-label')).toBe('Tacos')

        await wrapper.get('form').trigger('submit')
        expect(lastVisit().params).toMatchObject({ cuisine: 'tacos', lat: '30.2672', lng: '-97.7431' })
    })

    it('asks for a city when there is no area on screen', () => {
        const wrapper = mount(SiteSearch)
        expect(wrapper.get('[data-testid="location"]').attributes('data-placeholder')).toBe('City or ZIP, or use my location')
    })

    it('stacks the fields in the phone sheet', () => {
        const wrapper = mount(SiteSearch, { props: { stacked: true } })
        expect(wrapper.get('form').classes()).toContain('flex-col')
    })
})
