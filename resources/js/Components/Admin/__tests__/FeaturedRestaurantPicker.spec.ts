import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const { mockDelete } = vi.hoisted(() => ({ mockDelete: vi.fn() }))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return { ...(actual as object), router: { delete: mockDelete } }
})

import FeaturedRestaurantPicker from '@/Components/Admin/FeaturedRestaurantPicker.vue'

const stubs = {
    Card: { template: '<div><slot /></div>' },
    CardHeader: { template: '<div><slot /></div>' },
    CardTitle: { template: '<div><slot /></div>' },
    CardContent: { template: '<div><slot /></div>' },
    Button: { props: ['disabled', 'type'], template: '<button :type="type" :disabled="disabled"><slot /></button>' },
}

const current = {
    restaurant: { id: 8629, name: "Moose's Tooth Pub & Pizzeria", city: 'Anchorage', state: 'AK', slug: 'mooses-tooth' },
    story: { id: 6, title: 'The story', slug: 'the-story' },
    image_url: null,
    image_credit: null,
    starts_at: '2026-09-13T12:00:00Z',
    ends_at: null,
}

function mountPicker(featured: Record<string, unknown> = {}) {
    return mount(FeaturedRestaurantPicker, {
        props: { featured: { current: null, stories: [{ id: 6, title: 'The story' }], ...featured } as never },
        global: { stubs },
    })
}

describe('FeaturedRestaurantPicker', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        mockDelete.mockReset()
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve([{ id: 8629, name: "Moose's Tooth Pub & Pizzeria", city: 'Anchorage', state: 'AK', google_rating: 4.7, google_review_count: 12072 }]),
        })))
    })

    afterEach(() => {
        vi.useRealTimers()
        vi.unstubAllGlobals()
    })

    it('says what the home page features now, and offers to stop', async () => {
        const wrapper = mountPicker({ current })
        const text = wrapper.get('[data-testid="featured-current"]').text()
        expect(text).toContain("Moose's Tooth Pub & Pizzeria")
        expect(text).toContain('with the story “The story”')
        await wrapper.get('[data-testid="featured-stop"]').trigger('click')
        expect(mockDelete).toHaveBeenCalledWith('/admin/featured-restaurant', expect.anything())
    })

    it('explains the fallback when nothing is picked', () => {
        const wrapper = mountPicker()
        expect(wrapper.get('[data-testid="featured-none"]').text()).toContain('top-ranked restaurant near each visitor')
    })

    it('finds restaurants by name and lets one be chosen', async () => {
        const wrapper = mountPicker()
        expect(wrapper.get('[data-testid="featured-submit"]').attributes('disabled')).toBeDefined()

        await wrapper.get('#featured-search').setValue('moose')
        vi.advanceTimersByTime(300)
        await flushPromises()

        expect(fetch).toHaveBeenCalledWith('/admin/featured-restaurant/search?q=moose', expect.anything())
        const result = wrapper.get('[data-testid="featured-results"] button')
        expect(result.text()).toContain('4.7 (12,072)')
        await result.trigger('click')

        expect(wrapper.get('[data-testid="featured-chosen"]').text()).toContain("Moose's Tooth Pub & Pizzeria")
        expect(wrapper.get('[data-testid="featured-submit"]').attributes('disabled')).toBeUndefined()
    })

    it('lists the published stories to link', () => {
        const wrapper = mountPicker()
        expect(wrapper.get('#featured-story').text()).toContain('The story')
    })
})
