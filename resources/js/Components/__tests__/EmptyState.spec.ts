import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import EmptyState from '@/Components/EmptyState.vue'

describe('EmptyState', () => {
    it('renders nothing when no props provided', () => {
        const wrapper = mount(EmptyState)
        expect(wrapper.text()).toBe('')
    })

    it('renders icon when provided', () => {
        const wrapper = mount(EmptyState, {
            props: { icon: '🔍' },
        })
        expect(wrapper.text()).toContain('🔍')
    })

    it('renders title when provided', () => {
        const wrapper = mount(EmptyState, {
            props: { title: 'No results found' },
        })
        expect(wrapper.text()).toContain('No results found')
    })

    it('renders message when provided', () => {
        const wrapper = mount(EmptyState, {
            props: { message: 'Try adjusting your filters' },
        })
        expect(wrapper.text()).toContain('Try adjusting your filters')
    })

    it('renders action link when label and href provided', () => {
        const wrapper = mount(EmptyState, {
            props: {
                title: 'No favorites yet',
                actionLabel: 'Browse restaurants',
                actionHref: '/restaurants',
            },
        })
        const link = wrapper.find('a')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('Browse restaurants')
        expect(link.attributes('href')).toBe('/restaurants')
    })

    it('does not render action link when only label is provided', () => {
        const wrapper = mount(EmptyState, {
            props: { actionLabel: 'Click me' },
        })
        expect(wrapper.find('a').exists()).toBe(false)
    })
})
