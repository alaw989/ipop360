import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import HeroSearch from '@/Components/HeroSearch.vue'

function createWrapper(overrides: Partial<Record<'detectingLocation' | 'location', unknown>> = {}) {
    const props = {
        categories: [],
        location: { city: 'Miami', state: 'FL' },
        detectingLocation: false,
        ...overrides,
    }
    return mount(HeroSearch, {
        props,
        global: {
            stubs: {
                Button: { template: '<button :disabled="disabled"><slot /></button>' },
                CuisinePicker: { template: '<span data-test="cuisine-picker">any cuisine</span>' },
                LocationPicker: { template: '<span data-test="location-picker">Miami</span>' },
                BrandLogo: { template: '<span>iPop360</span>' },
            },
        },
    })
}

describe('HeroSearch', () => {
    it('renders the brand logo and hero heading', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('iPop360')
        expect(wrapper.text()).toContain('Find the most Popular')
        expect(wrapper.text()).toContain('Restaurants in')
    })

    it('renders nested cuisine and location pickers', () => {
        const wrapper = createWrapper()
        expect(wrapper.find('[data-test="cuisine-picker"]').exists()).toBe(true)
        expect(wrapper.find('[data-test="location-picker"]').exists()).toBe(true)
    })

    it('shows the Search button by default', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('Search')
        expect(wrapper.text()).not.toContain('Detecting location...')
    })

    it('shows "Detecting location..." when detecting and disables the button', () => {
        const wrapper = createWrapper({ detectingLocation: true })
        expect(wrapper.text()).toContain('Detecting location...')
        expect(wrapper.find('button').attributes('disabled')).toBeDefined()
    })

    it('emits search when the logo link is clicked', async () => {
        const wrapper = createWrapper()
        await wrapper.find('a[aria-label="iPop360 home"]').trigger('click')
        expect(wrapper.emitted('search')).toBeTruthy()
    })

    it('emits search when the Search button is clicked', async () => {
        const wrapper = createWrapper()
        await wrapper.find('button').trigger('click')
        expect(wrapper.emitted('search')).toBeTruthy()
    })
})