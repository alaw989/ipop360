import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import LocationPicker from '@/Components/LocationPicker.vue'

vi.mock('@/composables/useIsMobile', () => ({
    useIsMobile: () => ({ isMobile: ref(false) }),
}))

vi.mock('@/composables/useKeyboardOffset', () => ({
    useKeyboardOffset: () => ({ keyboardHeight: ref(0) }),
}))

const mockGet = vi.fn()
vi.mock('@/lib/api', () => ({
    get: mockGet,
}))

const slotStub = { template: '<div><slot /></div>' }

function createWrapper(overrides: Record<string, unknown> = {}) {
    return mount(LocationPicker, {
        props: {
            location: null,
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
            },
        },
    })
}

const mockResults = [
    {
        city: 'Austin',
        state: 'TX',
        country: 'US',
        lat: 30.2672,
        lng: -97.7431,
        display: 'Austin, TX, USA',
    },
    {
        city: 'Austin',
        state: 'MN',
        country: 'US',
        lat: 43.6666,
        lng: -92.9746,
        display: 'Austin, MN, USA',
    },
]

const singleResult = [
    {
        city: 'Dallas',
        state: 'TX',
        country: 'US',
        lat: 32.7767,
        lng: -96.797,
        display: 'Dallas, TX, USA',
    },
]

describe('LocationPicker', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        mockGet.mockReset()
        mockGet.mockResolvedValue([])
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('renders default "your city" text when no location prop', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('your city')
    })

    it('renders city name when location.city is provided', () => {
        const wrapper = createWrapper({
            location: { city: 'Austin', state: null },
        })
        expect(wrapper.text()).toContain('Austin')
        expect(wrapper.text()).not.toContain('your city')
    })

    it('renders "city, state" when both are provided', () => {
        const wrapper = createWrapper({
            location: { city: 'Dallas', state: 'TX' },
        })
        expect(wrapper.text()).toContain('Dallas, TX')
    })

    it('renders "Detecting..." and spinner when detecting prop is true', () => {
        const wrapper = createWrapper({ detecting: true })
        expect(wrapper.text()).toContain('Detecting...')
        expect(wrapper.find('svg.animate-spin').exists()).toBe(true)
    })

    it('shows "Type to search cities" when popover opens with short query', async () => {
        const wrapper = createWrapper()
        const trigger = wrapper.find('button')
        await trigger.trigger('click')

        expect(wrapper.text()).toContain('Type to search cities')
        expect(wrapper.text()).toContain('Use my current location')
    })

    it('clicking "Use my current location" emits detect and closes', async () => {
        const wrapper = createWrapper()
        const trigger = wrapper.find('button')
        await trigger.trigger('click')

        const useLocationBtn = wrapper.find('button.text-primary')
        await useLocationBtn.trigger('click')

        expect(wrapper.emitted('detect')).toBeTruthy()
    })

    it('shows "No cities found" when API returns empty and query >= 2 chars', async () => {
        const wrapper = createWrapper()
        const trigger = wrapper.find('button')
        await trigger.trigger('click')

        const input = wrapper.find('input[placeholder="Type your city..."]')
        await input.setValue('xy')
        await vi.advanceTimersByTimeAsync(300)

        expect(wrapper.text()).toContain('No cities found')
    })

    it('renders search results after debounced API call', async () => {
        mockGet.mockResolvedValue(mockResults)

        const wrapper = createWrapper()
        const trigger = wrapper.find('button')
        await trigger.trigger('click')

        const input = wrapper.find('input[placeholder="Type your city..."]')
        await input.setValue('Austin')
        await vi.advanceTimersByTimeAsync(300)

        expect(mockGet).toHaveBeenCalledWith(
            '/api/geocode/search?q=Austin',
        )

        const resultButtons = wrapper.findAll('button[class*="w-full"]')
        expect(resultButtons.length).toBe(2)
        expect(resultButtons[0]!.text()).toContain('Austin, TX')
        expect(resultButtons[0]!.text()).toContain('Austin, TX, USA')
    })

    it('selecting a result emits update and coords', async () => {
        mockGet.mockResolvedValue(singleResult)

        const wrapper = createWrapper()
        const trigger = wrapper.find('button')
        await trigger.trigger('click')

        const input = wrapper.find('input[placeholder="Type your city..."]')
        await input.setValue('Dallas')
        await vi.advanceTimersByTimeAsync(300)

        const resultBtn = wrapper.find('button[class*="w-full"]')
        await resultBtn.trigger('click')

        expect(wrapper.emitted('update')).toBeTruthy()
        expect(wrapper.emitted('update')![0]![0]).toEqual({
            city: 'Dallas',
            state: 'TX',
        })
        expect(wrapper.emitted('coords')).toBeTruthy()
        expect(wrapper.emitted('coords')![0]).toEqual([32.7767, -96.797])
    })

    it('shows result without display line when display is null', async () => {
        mockGet.mockResolvedValue([
            { city: 'Round Rock', state: 'TX', country: 'US', lat: 30.5083, lng: -97.6789, display: null },
        ])

        const wrapper = createWrapper()
        const trigger = wrapper.find('button')
        await trigger.trigger('click')

        const input = wrapper.find('input[placeholder="Type your city..."]')
        await input.setValue('Round Rock')
        await vi.advanceTimersByTimeAsync(300)

        const resultBtn = wrapper.find('button[class*="w-full"]')
        expect(resultBtn.text()).toContain('Round Rock, TX')
        expect(resultBtn.text()).not.toContain('null')
    })

    it('shows spinner while searching', async () => {
        mockGet.mockImplementation(
            () => new Promise((resolve) => setTimeout(() => resolve([]), 1000)),
        )

        const wrapper = createWrapper()
        const trigger = wrapper.find('button')
        await trigger.trigger('click')

        const input = wrapper.find('input[placeholder="Type your city..."]')
        await input.setValue('Test')

        expect(wrapper.find('span.animate-spin').exists()).toBe(true)
    })

    it('inverted prop applies styling classes', () => {
        const wrapper = createWrapper({ inverted: true })
        const btn = wrapper.find('button')
        expect(btn.classes()).toEqual(
            expect.arrayContaining(['border-white/30', 'text-white/80']),
        )
    })

    it('detecting prop adds animate-pulse class', () => {
        const wrapper = createWrapper({ detecting: true })
        const btn = wrapper.find('button')
        expect(btn.classes()).toContain('animate-pulse')
    })
})
