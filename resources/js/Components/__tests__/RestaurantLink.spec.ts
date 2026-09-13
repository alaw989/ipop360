import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { Link } from '@inertiajs/vue3'
import RestaurantLink from '@/Components/RestaurantLink.vue'
import type { Restaurant } from '@/types/restaurant'

function restaurant(overrides: Partial<Restaurant> = {}): Restaurant {
    return { id: 8629, name: "Moose's Tooth", slug: 'mooses-tooth', city: 'Anchorage', cuisines: [], ...overrides } as Restaurant
}

describe('RestaurantLink', () => {
    it('opens a saved restaurant inside the app, prefetched', () => {
        const wrapper = mount(RestaurantLink, { props: { restaurant: restaurant() }, slots: { default: 'Name' } })
        const link = wrapper.findComponent(Link)
        expect(link.exists()).toBe(true)
        expect(link.props('href')).toBe('/restaurants/mooses-tooth')
        expect(link.props('prefetch')).toBe(true)
        expect(wrapper.find('a').attributes('target')).toBeUndefined()
        expect(wrapper.text()).toBe('Name')
    })

    it('opens a live result in a new tab', () => {
        const wrapper = mount(RestaurantLink, { props: { restaurant: restaurant({ id: -3, slug: 'live-spot' }) } })
        expect(wrapper.findComponent(Link).exists()).toBe(false)
        const a = wrapper.get('a')
        expect(a.attributes('href')).toBe('/restaurants/preview/live-spot')
        expect(a.attributes('target')).toBe('_blank')
        expect(a.attributes('rel')).toBe('noopener')
    })

    it('passes its classes to the link', () => {
        const wrapper = mount(RestaurantLink, { props: { restaurant: restaurant() }, attrs: { class: 'after:inset-0' } })
        expect(wrapper.get('a').classes()).toContain('after:inset-0')
    })
})
