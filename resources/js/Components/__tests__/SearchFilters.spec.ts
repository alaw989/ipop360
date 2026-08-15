import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a><slot /></a>' },
}))

import SearchFilters from '@/Components/SearchFilters.vue'

const defaultFilterOptions = {
    categories: [
        { id: 1, name: 'Italian', slug: 'italian', restaurants_count: 42 },
        { id: 2, name: 'Japanese', slug: 'japanese', restaurants_count: 30 },
    ],
    priceOptions: ['$', '$$', '$$$', '$$$$'],
    distanceOptions: [1, 5, 10, 25, 50],
}

function mountSearchFilters(overrides: {
    filters?: Record<string, string | string[] | undefined>;
    filterOptions?: typeof defaultFilterOptions;
} = {}) {
    const props = {
        filters: overrides.filters ?? {},
        filterOptions: overrides.filterOptions ?? defaultFilterOptions,
    }
    return mount(SearchFilters, {
        props,
        global: {
            stubs: {
                Button: { template: '<button><slot /></button>' },
            },
        },
    })
}

describe('SearchFilters', () => {
    it('renders the filters heading', () => {
        const wrapper = mountSearchFilters()
        expect(wrapper.find('h2').text()).toBe('Filters')
    })

    it('renders all price option buttons', () => {
        const wrapper = mountSearchFilters()
        const priceSection = wrapper.findAll('h3').filter(n => n.text() === 'Price')
        expect(priceSection.length).toBe(1)
        const buttons = wrapper.findAll('.flex.gap-1 button')
        expect(buttons).toHaveLength(4)
        expect(buttons[0].text()).toBe('$')
        expect(buttons[3].text()).toBe('$$$$')
    })

    it('applies active class to the selected price button', () => {
        const wrapper = mountSearchFilters({ filters: { price_range: '$$' } })
        const priceSection = wrapper.findAll('h3').filter(n => n.text() === 'Price')
        const buttons = wrapper.findAll('.flex.gap-1 button')
        const activeButton = buttons.find(b => b.text() === '$$')
        expect(activeButton?.classes()).toContain('bg-primary')
        const inactiveButton = buttons.find(b => b.text() === '$')
        expect(inactiveButton?.classes()).not.toContain('bg-primary')
    })

    it('emits update with the selected price when clicking an inactive price', () => {
        const wrapper = mountSearchFilters({ filters: { price_range: '$$' } })
        const buttons = wrapper.findAll('.flex.gap-1 button')
        const dollarButton = buttons.find(b => b.text() === '$')
        dollarButton!.trigger('click')
        expect(wrapper.emitted('update')).toBeTruthy()
        expect(wrapper.emitted('update')![0]).toEqual([{ price_range: '$' }])
    })

    it('emits update with price_range undefined when clicking the active price (toggle off)', () => {
        const wrapper = mountSearchFilters({ filters: { price_range: '$$' } })
        const buttons = wrapper.findAll('.flex.gap-1 button')
        const activeButton = buttons.find(b => b.text() === '$$')
        activeButton!.trigger('click')
        expect(wrapper.emitted('update')![0]).toEqual([{ price_range: undefined }])
    })

    it('renders category links with names and restaurant counts', () => {
        const wrapper = mountSearchFilters()
        const categorySection = wrapper.findAll('h3').filter(n => n.text() === 'Category')
        expect(categorySection.length).toBe(1)
        const links = wrapper.findAll('a')
        expect(links).toHaveLength(2)
        expect(links[0].text()).toContain('Italian')
        expect(links[0].text()).toContain('42')
        expect(links[1].text()).toContain('Japanese')
        expect(links[1].text()).toContain('30')
    })

    it('applies active class to the selected category link', () => {
        const wrapper = mountSearchFilters({ filters: { category: 'italian' } })
        const links = wrapper.findAll('a')
        const italianLink = links.find(a => a.text().includes('Italian'))
        expect(italianLink?.classes()).toContain('bg-primary/10')
        const japaneseLink = links.find(a => a.text().includes('Japanese'))
        expect(japaneseLink?.classes()).not.toContain('bg-primary/10')
    })

    it('renders distance radio buttons', () => {
        const wrapper = mountSearchFilters()
        const distanceSection = wrapper.findAll('h3').filter(n => n.text() === 'Distance')
        expect(distanceSection.length).toBe(1)
        const radios = wrapper.findAll('input[type="radio"]')
        expect(radios).toHaveLength(6) // 5 options + Auto
    })

    it('checks the radio matching current distance filter', () => {
        const wrapper = mountSearchFilters({ filters: { distance: '10' } })
        const radio10 = wrapper.find('input[value="10"]')
        expect((radio10.element as HTMLInputElement).checked).toBe(true)
    })

    it('defaults distance to "25" when no filter is set', () => {
        const wrapper = mountSearchFilters()
        const radio25 = wrapper.find('input[value="25"]')
        expect((radio25.element as HTMLInputElement).checked).toBe(true)
    })

    it('emits update when a distance radio is changed', () => {
        const wrapper = mountSearchFilters()
        const radio5 = wrapper.find('input[value="5"]')
        radio5.trigger('change')
        expect(wrapper.emitted('update')![0]).toEqual([{ distance: '5' }])
    })

    it('emits update with distance undefined when Auto is selected', () => {
        const wrapper = mountSearchFilters({ filters: { distance: '5' } })
        const autoRadio = wrapper.find('input[value="0"]')
        autoRadio.trigger('change')
        expect(wrapper.emitted('update')![0]).toEqual([{ distance: undefined }])
    })

    it('shows distance labels with correct formatting', () => {
        const wrapper = mountSearchFilters()
        const labels = wrapper.findAll('label span').filter(s => s.text().includes('mi'))
        expect(labels[0].text()).toBe('1 mi')
        expect(labels[1].text()).toBe('5 mi')
        expect(labels[4].text()).toBe('50+ mi')
    })

    it('shows "Auto" as the label for the 0-value radio', () => {
        const wrapper = mountSearchFilters()
        const autoLabel = wrapper.findAll('label span').find(s => s.text() === 'Auto')
        expect(autoLabel?.exists()).toBe(true)
    })

    it('shows the "Clear all" button when a price filter is active', () => {
        const wrapper = mountSearchFilters({ filters: { price_range: '$$$' } })
        const clearButton = wrapper.findAll('button').find(b => b.text() === 'Clear all')
        expect(clearButton?.exists()).toBe(true)
    })

    it('shows the "Clear all" button when distance is not default', () => {
        const wrapper = mountSearchFilters({ filters: { distance: '10' } })
        const clearButton = wrapper.findAll('button').find(b => b.text() === 'Clear all')
        expect(clearButton?.exists()).toBe(true)
    })

    it('shows the "Clear all" button when a cuisine filter is active', () => {
        const wrapper = mountSearchFilters({ filters: { cuisine: 'pizza' } })
        const clearButton = wrapper.findAll('button').find(b => b.text() === 'Clear all')
        expect(clearButton?.exists()).toBe(true)
    })

    it('shows the "Clear all" button when a category filter is active', () => {
        const wrapper = mountSearchFilters({ filters: { category: 'italian' } })
        const clearButton = wrapper.findAll('button').find(b => b.text() === 'Clear all')
        expect(clearButton?.exists()).toBe(true)
    })

    it('does not show the "Clear all" button when no filters are active', () => {
        const wrapper = mountSearchFilters()
        const clearButton = wrapper.findAll('button').find(b => b.text() === 'Clear all')
        expect(clearButton).toBeUndefined()
    })

    it('does not show the "Clear all" button when only default distance is set', () => {
        const wrapper = mountSearchFilters({ filters: { distance: '25' } })
        const clearButton = wrapper.findAll('button').find(b => b.text() === 'Clear all')
        expect(clearButton).toBeUndefined()
    })

    it('emits clear when "Clear all" button is clicked', () => {
        const wrapper = mountSearchFilters({ filters: { price_range: '$$' } })
        const clearButton = wrapper.findAll('button').find(b => b.text() === 'Clear all')
        clearButton!.trigger('click')
        expect(wrapper.emitted('clear')).toBeTruthy()
        expect(wrapper.emitted('clear')!.length).toBe(1)
    })
})
