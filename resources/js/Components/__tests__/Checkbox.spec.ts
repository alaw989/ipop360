import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import Checkbox from '@/Components/Checkbox.vue'

describe('Checkbox', () => {
    it('renders an input of type checkbox', () => {
        const wrapper = mount(Checkbox, { props: { checked: false } })
        const input = wrapper.find('input')
        expect(input.exists()).toBe(true)
        expect(input.attributes('type')).toBe('checkbox')
    })

    it('applies the expected CSS classes', () => {
        const wrapper = mount(Checkbox, { props: { checked: false } })
        const input = wrapper.find('input')
        expect(input.classes()).toContain('rounded')
        expect(input.classes()).toContain('border-gray-300')
        expect(input.classes()).toContain('text-indigo-600')
        expect(input.classes()).toContain('shadow-sm')
        expect(input.classes()).toContain('focus:ring-indigo-500')
    })

    it('is checked when checked prop is true', () => {
        const wrapper = mount(Checkbox, { props: { checked: true } })
        const input = wrapper.find('input')
        expect(input.element.checked).toBe(true)
    })

    it('is not checked when checked prop is false', () => {
        const wrapper = mount(Checkbox, { props: { checked: false } })
        const input = wrapper.find('input')
        expect(input.element.checked).toBe(false)
    })

    it('emits update:checked with true when checked', async () => {
        const wrapper = mount(Checkbox, { props: { checked: false } })
        await wrapper.find('input').setChecked(true)
        expect(wrapper.emitted('update:checked')).toBeTruthy()
        expect(wrapper.emitted('update:checked')![0]).toEqual([true])
    })

    it('emits update:checked with false when unchecked', async () => {
        const wrapper = mount(Checkbox, { props: { checked: true } })
        await wrapper.find('input').setChecked(false)
        expect(wrapper.emitted('update:checked')).toBeTruthy()
        expect(wrapper.emitted('update:checked')![0]).toEqual([false])
    })

    it('passes value prop to the input value attribute', () => {
        const wrapper = mount(Checkbox, { props: { checked: false, value: 'agree' } })
        const input = wrapper.find('input')
        expect(input.attributes('value')).toBe('agree')
    })

    it('does not render value attribute when value prop is undefined', () => {
        const wrapper = mount(Checkbox, { props: { checked: false } })
        const input = wrapper.find('input')
        expect(input.attributes('value')).toBeUndefined()
    })

    it('does not emit when checked prop is updated externally', async () => {
        const wrapper = mount(Checkbox, { props: { checked: false } })
        await wrapper.setProps({ checked: true })
        expect(wrapper.emitted('update:checked')).toBeFalsy()
    })
})
