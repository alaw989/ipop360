import { describe, it, expect } from 'vitest'
import SheetTitle from '@/components/ui/sheet/SheetTitle.vue'
import { mountInSheet } from './sheet.spec.helpers'

const SLOT = 'Edit profile'

describe('SheetTitle', () => {
    it('renders a semantic heading with the slot content', () => {
        const wrapper = mountInSheet(SheetTitle, {}, SLOT)
        expect(wrapper.find('h2').exists()).toBe(true)
        expect(wrapper.find('h2').text()).toBe(SLOT)
    })

    it('renders the reka-ui DialogTitle primitive with data-slot', () => {
        const wrapper = mountInSheet(SheetTitle, {}, SLOT)
        expect(wrapper.find('[data-slot="sheet-title"]').exists()).toBe(true)
    })

    it('applies the default classes', () => {
        const wrapper = mountInSheet(SheetTitle, {}, SLOT)
        const heading = wrapper.find('h2')
        expect(heading.classes()).toContain('text-foreground')
        expect(heading.classes()).toContain('font-semibold')
    })

    it('merges a class prop into the default classes', () => {
        const wrapper = mountInSheet(SheetTitle, { class: 'custom-class' }, SLOT)
        const heading = wrapper.find('h2')
        expect(heading.classes()).toContain('custom-class')
        expect(heading.classes()).toContain('font-semibold')
    })
})
