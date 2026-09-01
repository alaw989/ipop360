import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import CuisinePicker from '@/Components/CuisinePicker.vue'

const isMobile = ref(false)

vi.mock('@/composables/useIsMobile', () => ({
    useIsMobile: () => ({ isMobile }),
}))

const categories = [
    {
        id: 1,
        name: 'Asian',
        slug: 'asian',
        icon: '🍜',
        cuisines: [
            { id: 1, name: 'Chinese', slug: 'chinese', icon: '🥡' },
            { id: 2, name: 'Japanese', slug: 'japanese', icon: '🍣' },
        ],
    },
    {
        id: 2,
        name: 'European',
        slug: 'european',
        icon: '🍷',
        cuisines: [
            { id: 3, name: 'Italian', slug: 'italian', icon: '🍝' },
        ],
    },
]

const slotStub = { template: '<div><slot /></div>' }

function createWrapper(overrides: Record<string, unknown> = {}) {
    return mount(CuisinePicker, {
        props: {
            categories,
            ...overrides,
        },
        global: {
            stubs: {
                Popover: slotStub,
                PopoverTrigger: slotStub,
                PopoverContent: slotStub,
                Sheet: slotStub,
                SheetTrigger: slotStub,
                SheetContent: slotStub,
                SheetTitle: { template: '<h2 data-testid="sheet-title"><slot /></h2>' },
                SheetDescription: { template: '<p data-testid="sheet-description"><slot /></p>' },
                Command: slotStub,
                CommandInput: { template: '<input data-test="cmd-input" />' },
                CommandList: slotStub,
                CommandGroup: slotStub,
                CommandEmpty: slotStub,
                CommandItem: {
                    template: '<div class="cmd-item" @click="$emit(\'select\')"><slot /></div>',
                },
            },
        },
    })
}

describe('CuisinePicker', () => {
    it('renders default "any cuisine" text', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('any cuisine')
    })

    it('renders category names in the dropdown', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('Asian')
        expect(wrapper.text()).toContain('European')
    })

    it('renders "Categories" group heading', () => {
        const wrapper = createWrapper()
        expect(wrapper.html()).toContain('Categories')
    })

    it('renders cuisine count per category', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('2')
        expect(wrapper.text()).toContain('1')
    })

    it('drills into a category when clicked and shows cuisines', async () => {
        const wrapper = createWrapper()
        const items = wrapper.findAll('.cmd-item')
        await items[0]!.trigger('click')

        expect(wrapper.text()).toContain('Back to categories')
        expect(wrapper.text()).toContain('All Asian')
        expect(wrapper.text()).toContain('Chinese')
        expect(wrapper.text()).toContain('Japanese')
    })

    it('emits select with category and cuisine when a cuisine is clicked', async () => {
        const wrapper = createWrapper()
        const items = wrapper.findAll('.cmd-item')
        await items[0]!.trigger('click')

        const cuisineItems = wrapper.findAll('.cmd-item')
        await cuisineItems[2]!.trigger('click')

        expect(wrapper.emitted('select')).toBeTruthy()
        expect(wrapper.emitted('select')![0]![0]).toEqual({
            category: 'asian',
            cuisine: 'chinese',
            label: 'Asian ▸ Chinese',
        })
    })

    it('emits select with only category when "All [category]" is clicked', async () => {
        const wrapper = createWrapper()
        const items = wrapper.findAll('.cmd-item')
        await items[0]!.trigger('click')

        const drillItems = wrapper.findAll('.cmd-item')
        await drillItems[1]!.trigger('click')

        expect(wrapper.emitted('select')![0]![0]).toEqual({
            category: 'asian',
            label: 'Asian',
        })
    })

    it('goes back to categories from drill-down', async () => {
        const wrapper = createWrapper()
        const items = wrapper.findAll('.cmd-item')
        await items[0]!.trigger('click')

        expect(wrapper.text()).toContain('Back to categories')
        expect(wrapper.text()).toContain('All Asian')

        const backItem = wrapper.findAll('.cmd-item')[0]!
        await backItem.trigger('click')

        expect(wrapper.text()).toContain('Asian')
        expect(wrapper.text()).toContain('European')
        expect(wrapper.text()).not.toContain('Back to categories')
    })

    it('updates trigger display text after confirming a category', async () => {
        const wrapper = createWrapper()
        const items = wrapper.findAll('.cmd-item')
        await items[1]!.trigger('click')

        expect(wrapper.text()).toContain('any cuisine')

        const drillItems = wrapper.findAll('.cmd-item')
        await drillItems[1]!.trigger('click')

        expect(wrapper.text()).toContain('European')
        expect(wrapper.text()).not.toContain('any cuisine')
    })

    it('shows clear selection option when a selection is active', async () => {
        const wrapper = createWrapper()
        const items = wrapper.findAll('.cmd-item')
        await items[1]!.trigger('click')

        const drillItems = wrapper.findAll('.cmd-item')
        await drillItems[1]!.trigger('click')

        expect(wrapper.text()).toContain('Clear selection')
    })

    it('clears selection and emits empty category on clear', async () => {
        const wrapper = createWrapper()

        const items = wrapper.findAll('.cmd-item')
        await items[1]!.trigger('click')

        const drillItems = wrapper.findAll('.cmd-item')
        await drillItems[1]!.trigger('click')

        expect(wrapper.emitted('select')).toHaveLength(1)
        expect(wrapper.emitted('select')![0]![0]).toEqual({
            category: 'european',
            label: 'European',
        })

        const clearItem = wrapper.findAll('.cmd-item').at(-1)!
        await clearItem.trigger('click')

        expect(wrapper.emitted('select')).toHaveLength(2)
        expect(wrapper.emitted('select')![1]![0]).toEqual({
            category: '',
            label: 'any cuisine',
        })
        expect(wrapper.text()).toContain('any cuisine')
    })

    it('renders with inverted styling when inverted prop is true', () => {
        const wrapper = createWrapper({ inverted: true })
        const btn = wrapper.find('button')
        expect(btn.classes()).toEqual(
            expect.arrayContaining(['border-white/30', 'text-white/70']),
        )
    })

    it('renders accessible SheetTitle and SheetDescription on mobile', () => {
        isMobile.value = true
        const wrapper = createWrapper()

        expect(wrapper.find('[data-testid="sheet-title"]').text()).toBe('Choose a cuisine')
        expect(wrapper.find('[data-testid="sheet-description"]').exists()).toBe(true)

        isMobile.value = false
    })

    it('selectCuisine guards against null drillCategory instead of crashing', () => {
        const wrapper = createWrapper()
        const vm = wrapper.vm as unknown as {
            selectCuisine: (c: { id: number; name: string; slug: string; icon: string | null }) => void
        }

        expect(() => vm.selectCuisine({ id: 1, name: 'Chinese', slug: 'chinese', icon: '🥡' })).not.toThrow()
        expect(wrapper.emitted('select')).toBeUndefined()
        expect(wrapper.text()).toContain('any cuisine')
    })

    it('confirmCategory guards against null drillCategory instead of crashing', () => {
        const wrapper = createWrapper()
        const vm = wrapper.vm as unknown as { confirmCategory: () => void }

        expect(() => vm.confirmCategory()).not.toThrow()
        expect(wrapper.emitted('select')).toBeUndefined()
        expect(wrapper.text()).toContain('any cuisine')
    })
})
