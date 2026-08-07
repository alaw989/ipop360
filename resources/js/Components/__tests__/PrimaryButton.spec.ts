import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PrimaryButton from '@/Components/PrimaryButton.vue'

describe('PrimaryButton', () => {
    it('renders a button element', () => {
        const wrapper = mount(PrimaryButton)
        expect(wrapper.find('button').exists()).toBe(true)
    })

    it('renders slot content', () => {
        const wrapper = mount(PrimaryButton, {
            slots: { default: 'Click me' },
        })
        expect(wrapper.text()).toBe('Click me')
    })

    it('applies the expected CSS classes', () => {
        const wrapper = mount(PrimaryButton)
        const button = wrapper.find('button')
        expect(button.classes()).toContain('bg-gray-800')
        expect(button.classes()).toContain('text-white')
        expect(button.classes()).toContain('rounded-md')
        expect(button.classes()).toContain('inline-flex')
        expect(button.classes()).toContain('hover:bg-gray-700')
        expect(button.classes()).toContain('focus:ring-indigo-500')
    })

    it('renders as a native HTML button (not a Link)', () => {
        const wrapper = mount(PrimaryButton)
        expect(wrapper.find('button').element.tagName).toBe('BUTTON')
    })
})
