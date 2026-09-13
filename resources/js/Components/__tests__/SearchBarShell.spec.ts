import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SearchBarShell from '@/Components/SearchBarShell.vue'

function mountShell(props: Record<string, unknown> = {}) {
    return mount(SearchBarShell, {
        props,
        slots: {
            what: '<button type="button" data-testid="what">What</button>',
            where: '<button type="button" data-testid="where">Where</button>',
        },
    })
}

describe('SearchBarShell', () => {
    it('is a search landmark with the what and where slots', () => {
        const wrapper = mountShell()
        const form = wrapper.get('form')
        expect(form.attributes('role')).toBe('search')
        expect(wrapper.find('[data-testid="search-what"] [data-testid="what"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="search-where"] [data-testid="where"]').exists()).toBe(true)
    })

    it('emits submit when the form is submitted', async () => {
        const wrapper = mountShell()
        await wrapper.get('form').trigger('submit')
        expect(wrapper.emitted('submit')).toHaveLength(1)
    })

    it('keeps the word Search for screen readers on the compact header button', () => {
        const wrapper = mountShell({ layout: 'row', size: 'md' })
        const label = wrapper.get('[data-testid="search-submit"] span')
        expect(label.text()).toBe('Search')
        expect(label.classes()).toContain('sr-only')
    })

    it('stacks the fields with a full-width button in the phone sheet', () => {
        const wrapper = mountShell({ layout: 'stacked' })
        expect(wrapper.get('form').classes()).toContain('flex-col')
        expect(wrapper.get('[data-testid="search-submit"]').classes()).toContain('w-full')
    })

    it('stacks on phones and lines up from 640px in the hero', () => {
        const wrapper = mountShell({ layout: 'responsive', size: 'lg' })
        expect(wrapper.get('form').classes()).toEqual(expect.arrayContaining(['flex-col', 'sm:flex-row']))
    })

    it('disables the button while busy', () => {
        const wrapper = mountShell({ busy: true })
        expect(wrapper.get('[data-testid="search-submit"]').attributes('disabled')).toBeDefined()
    })
})
