import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import StarRating from '@/Components/StarRating.vue'

describe('StarRating', () => {
    it('renders 5 full stars for rating 5.0', () => {
        const wrapper = mount(StarRating, { props: { rating: 5.0 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(5)
        expect(wrapper.text()).toContain('5.0')
    })

    it('renders 0 full stars for rating 0', () => {
        const wrapper = mount(StarRating, { props: { rating: 0 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(0)
        expect(wrapper.findAll('svg[data-star="empty"]').length).toBe(5)
    })

    it('renders half star when decimal >= 0.25', () => {
        const wrapper = mount(StarRating, { props: { rating: 3.5 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(3)
        expect(wrapper.findAll('svg[data-star="half"]').length).toBe(1)
        expect(wrapper.find('path[fill^="url(#half-"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('3.5')
    })

    it('does not render half star when decimal < 0.25', () => {
        const wrapper = mount(StarRating, { props: { rating: 3.2 } })
        expect(wrapper.text()).toContain('3.2')
    })

    it('includes source label when provided', () => {
        const wrapper = mount(StarRating, { props: { rating: 4.0, source: 'Yelp' } })
        expect(wrapper.text()).toContain('Yelp')
    })

    it('includes review count when provided', () => {
        const wrapper = mount(StarRating, {
            props: { rating: 4.0, reviewCount: 1234 },
        })
        expect(wrapper.text()).toContain('1,234')
    })

    it('renders with Google source', () => {
        const wrapper = mount(StarRating, {
            props: { rating: 4.2, source: 'Google', reviewCount: 567 },
        })
        expect(wrapper.text()).toContain('Google')
        expect(wrapper.text()).toContain('567')
    })

    it('applies size class for sm', () => {
        const wrapper = mount(StarRating, { props: { rating: 3.0, size: 'sm' } })
        const outer = wrapper.find('span:first-child')
        expect(outer.classes()).toContain('text-sm')
    })

    it('applies size class for md (default)', () => {
        const wrapper = mount(StarRating, { props: { rating: 3.0 } })
        const outer = wrapper.find('span:first-child')
        expect(outer.classes()).toContain('text-base')
    })

    it('renders 4 full stars and 1 empty for rating 4.0', () => {
        const wrapper = mount(StarRating, { props: { rating: 4.0 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(4)
        expect(wrapper.findAll('svg[data-star="empty"]').length).toBe(1)
    })

    it('bumps to a full star when fractional part > 0.75 (4.9)', () => {
        const wrapper = mount(StarRating, { props: { rating: 4.9 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(5)
        expect(wrapper.findAll('svg[data-star="half"]').length).toBe(0)
    })

    it('renders a half star for fractional part in 0.25–0.75 (4.3)', () => {
        const wrapper = mount(StarRating, { props: { rating: 4.3 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(4)
        expect(wrapper.findAll('svg[data-star="half"]').length).toBe(1)
    })

    it('clamps rating over max to max stars (6.5 with max 5)', () => {
        const wrapper = mount(StarRating, { props: { rating: 6.5, max: 5 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(5)
        expect(wrapper.findAll('svg[data-star="half"]').length).toBe(0)
        expect(wrapper.findAll('svg[data-star="empty"]').length).toBe(0)
    })

    it('renders no half and no full beyond max when over max', () => {
        const wrapper = mount(StarRating, { props: { rating: 7.3, max: 5 } })
        expect(wrapper.findAll('svg[data-star="full"]').length).toBe(5)
        expect(wrapper.findAll('svg[data-star="half"]').length).toBe(0)
    })

    it('says whose reviews they are next to the count', () => {
        const wrapper = mount(StarRating, { props: { rating: 4.8, source: 'Google', reviewCount: 210 } })
        expect(wrapper.text()).toContain('210 Google reviews')
    })

    it('uses the singular for one review', () => {
        const wrapper = mount(StarRating, { props: { rating: 5, source: 'Google', reviewCount: 1 } })
        expect(wrapper.text()).toContain('1 Google review')
        expect(wrapper.text()).not.toContain('reviews')
    })

    it('draws stars in the rating color and empty stars in the border color', () => {
        const wrapper = mount(StarRating, { props: { rating: 4.0 } })
        expect(wrapper.find('svg[data-star="full"]').classes()).toContain('text-rating')
        expect(wrapper.find('svg[data-star="empty"]').classes()).toContain('text-border')
    })
})
