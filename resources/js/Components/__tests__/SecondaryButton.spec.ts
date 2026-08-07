import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SecondaryButton from '@/Components/SecondaryButton.vue'

describe('SecondaryButton', () => {
    it('renders a button element', () => {
        const wrapper = mount(SecondaryButton)
        expect(wrapper.find('button').exists()).toBe(true)
    })

    it('renders slot content', () => {
        const wrapper = mount(SecondaryButton, {
            slots: { default: 'Cancel' },
        })
        expect(wrapper.text()).toBe('Cancel')
    })

    it('applies the expected CSS classes', () => {
        const wrapper = mount(SecondaryButton)
        const button = wrapper.find('button')
        expect(button.classes()).toContain('bg-white')
        expect(button.classes()).toContain('text-gray-700')
        expect(button.classes()).toContain('rounded-md')
        expect(button.classes()).toContain('inline-flex')
        expect(button.classes()).toContain('border')
        expect(button.classes()).toContain('border-gray-300')
        expect(button.classes()).toContain('hover:bg-gray-50')
        expect(button.classes()).toContain('focus:ring-indigo-500')
    })

    it('renders as a native HTML BUTTON (not a Link)', () => {
        const wrapper = mount(SecondaryButton)
        expect(wrapper.find('button').element.tagName).toBe('BUTTON')
    })

    it('default type is "button"', () => {
        const wrapper = mount(SecondaryButton)
        expect(wrapper.find('button').attributes('type')).toBe('button')
    })

    it('can override type to "submit"', () => {
        const wrapper = mount(SecondaryButton, {
            props: { type: 'submit' },
        })
        expect(wrapper.find('button').attributes('type')).toBe('submit')
    })
})
