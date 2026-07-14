import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import LoadingState from '@/Components/LoadingState.vue'

describe('LoadingState', () => {
    it('renders default number of skeleton items', () => {
        const wrapper = mount(LoadingState)
        const skeletons = wrapper.findAll('[class*="h-48"]')
        expect(skeletons.length).toBeGreaterThan(0)
    })

    it('accepts custom count prop', () => {
        const wrapper = mount(LoadingState, {
            props: { count: 3 },
        })
        // Each skeleton group has a h-48 skeleton + 2 text skeletons
        expect(wrapper.text()).toBe('')
    })

    it('accepts custom columns prop', () => {
        const wrapper = mount(LoadingState, {
            props: { columns: 4 },
        })
        const grid = wrapper.find('div')
        expect(grid.attributes('style')).toContain('repeat(4')
    })
})
