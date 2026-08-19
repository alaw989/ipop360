import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'
import Sheet from '@/components/ui/sheet/Sheet.vue'
import SheetTitle from '@/components/ui/sheet/SheetTitle.vue'

const mountInSheet = (props: Record<string, unknown> = {}, slot = 'Edit profile') =>
    mount(Sheet, {
        slots: { default: () => h(SheetTitle, { ...props }, () => slot) },
    })

describe('SheetTitle', () => {
    it('renders a semantic heading with the slot content', () => {
        const wrapper = mountInSheet()
        expect(wrapper.find('h2').exists()).toBe(true)
        expect(wrapper.find('h2').text()).toBe('Edit profile')
    })

    it('renders the reka-ui DialogTitle primitive with data-slot', () => {
        const wrapper = mountInSheet()
        expect(wrapper.find('[data-slot="sheet-title"]').exists()).toBe(true)
    })

    it('applies the default classes', () => {
        const wrapper = mountInSheet()
        const heading = wrapper.find('h2')
        expect(heading.classes()).toContain('text-foreground')
        expect(heading.classes()).toContain('font-semibold')
    })

    it('merges a class prop into the default classes', () => {
        const wrapper = mountInSheet({ class: 'custom-class' })
        const heading = wrapper.find('h2')
        expect(heading.classes()).toContain('custom-class')
        expect(heading.classes()).toContain('font-semibold')
    })

    it('forwards the as prop to change the rendered element', () => {
        const wrapper = mountInSheet({ as: 'h3' })
        const heading = wrapper.find('h3')
        expect(heading.exists()).toBe(true)
        expect(heading.text()).toBe('Edit profile')
    })
})