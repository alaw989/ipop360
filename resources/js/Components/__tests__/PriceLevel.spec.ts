import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PriceLevel from '@/Components/PriceLevel.vue'

describe('PriceLevel', () => {
    it('shows all four signs with the level dark and the rest light', () => {
        const wrapper = mount(PriceLevel, { props: { price: '$$' } })
        const parts = wrapper.findAll('[data-testid="price-level"] > span')
        expect(parts[0]!.text()).toBe('$$')
        expect(parts[0]!.classes()).toContain('text-foreground')
        expect(parts[1]!.text()).toBe('$$')
        expect(parts[1]!.classes()).toContain('text-muted-foreground/40')
    })

    it('names the level for screen readers and on hover', () => {
        const wrapper = mount(PriceLevel, { props: { price: '$$$' } })
        const level = wrapper.get('[data-testid="price-level"]')
        expect(level.attributes('aria-label')).toBe('Price: pricey')
        expect(level.attributes('title')).toBe('Pricey')
    })

    it.each([
        ['$', 'Price: inexpensive'],
        ['$$', 'Price: moderate'],
        ['$$$', 'Price: pricey'],
        ['$$$$', 'Price: high-end'],
    ])('reads %s as "%s"', (price, label) => {
        const wrapper = mount(PriceLevel, { props: { price } })
        expect(wrapper.get('[data-testid="price-level"]').attributes('aria-label')).toBe(label)
    })

    it('keeps the stored currency sign', () => {
        const wrapper = mount(PriceLevel, { props: { price: '€€' } })
        expect(wrapper.get('[data-testid="price-level"]').text()).toBe('€€€€')
    })

    it('leaves an unrecognized value as stored', () => {
        const wrapper = mount(PriceLevel, { props: { price: '$10–20' } })
        expect(wrapper.find('[data-testid="price-level"]').exists()).toBe(false)
        expect(wrapper.text()).toBe('$10–20')
    })

    it('renders nothing without a price', () => {
        expect(mount(PriceLevel, { props: { price: null } }).text()).toBe('')
        expect(mount(PriceLevel, { props: { price: '' } }).text()).toBe('')
    })
})
