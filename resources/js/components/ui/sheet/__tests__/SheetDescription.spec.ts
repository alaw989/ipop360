import { describe, it, expect } from 'vitest'
import SheetDescription from '@/components/ui/sheet/SheetDescription.vue'
import { mountInSheet } from './sheet.spec.helpers'

const SLOT = 'Your profile is visible to the public.'

describe('SheetDescription', () => {
    it('renders a paragraph with the slot content', () => {
        const wrapper = mountInSheet(SheetDescription, {}, SLOT)
        expect(wrapper.find('p').exists()).toBe(true)
        expect(wrapper.find('p').text()).toBe(SLOT)
    })

    it('renders the reka-ui DialogDescription primitive with data-slot', () => {
        const wrapper = mountInSheet(SheetDescription, {}, SLOT)
        expect(wrapper.find('[data-slot="sheet-description"]').exists()).toBe(true)
    })

    it('applies the default classes', () => {
        const wrapper = mountInSheet(SheetDescription, {}, SLOT)
        const paragraph = wrapper.find('p')
        expect(paragraph.classes()).toContain('text-muted-foreground')
        expect(paragraph.classes()).toContain('text-sm')
    })

    it('merges a class prop into the default classes', () => {
        const wrapper = mountInSheet(SheetDescription, { class: 'custom-class' }, SLOT)
        const paragraph = wrapper.find('p')
        expect(paragraph.classes()).toContain('custom-class')
        expect(paragraph.classes()).toContain('text-sm')
    })
})
