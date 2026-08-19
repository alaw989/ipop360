import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Sheet from '@/components/ui/sheet/Sheet.vue'
import SheetDescription from '@/components/ui/sheet/SheetDescription.vue'

const mountInSheet = (props: Record<string, unknown> = {}, slot = 'Your profile is visible to the public.') =>
    mount(Sheet, {
        slots: { default: () => h(SheetDescription, { ...props }, () => slot) },
    })

describe('SheetDescription', () => {
    it('renders a paragraph with the slot content', () => {
        const wrapper = mountInSheet()
        expect(wrapper.find('p').exists()).toBe(true)
        expect(wrapper.find('p').text()).toBe('Your profile is visible to the public.')
    })

    it('renders the reka-ui DialogDescription primitive with data-slot', () => {
        const wrapper = mountInSheet()
        expect(wrapper.find('[data-slot="sheet-description"]').exists()).toBe(true)
    })

    it('applies the default classes', () => {
        const wrapper = mountInSheet()
        const paragraph = wrapper.find('p')
        expect(paragraph.classes()).toContain('text-muted-foreground')
        expect(paragraph.classes()).toContain('text-sm')
    })

    it('merges a class prop into the default classes', () => {
        const wrapper = mountInSheet({ class: 'custom-class' })
        const paragraph = wrapper.find('p')
        expect(paragraph.classes()).toContain('custom-class')
        expect(paragraph.classes()).toContain('text-sm')
    })

    it('forwards the as prop to change the rendered element', () => {
        const wrapper = mountInSheet({ as: 'div' })
        const el = wrapper.find('div[data-slot="sheet-description"]')
        expect(el.exists()).toBe(true)
        expect(el.text()).toBe('Your profile is visible to the public.')
    })
})