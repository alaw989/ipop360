import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import HeroBanner from '@/Components/HeroBanner.vue'

vi.mock('@/lib/slideshow', () => ({
    slides: [
        { image: '/img/slide1.jpg', attribution: 'Photo by Tester 1' },
        { image: '/img/slide2.jpg', attribution: 'Photo by Tester 2' },
    ],
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

vi.mock('@/Components/CuisinePicker.vue', () => ({
    default: {
        template: '<div class="cuisine-picker-stub" data-testid="cuisine-picker"><slot /></div>',
        props: ['categories', 'inverted'],
        emits: ['select'],
    },
}))

vi.mock('@/Components/LocationPicker.vue', () => ({
    default: {
        template: '<div class="location-picker-stub" data-testid="location-picker"><slot /></div>',
        props: ['location', 'detecting', 'inverted'],
        emits: ['update', 'coords', 'detect'],
    },
}))

vi.mock('@/Components/BrandLogo.vue', () => ({
    default: {
        template: '<span class="brand-logo-stub">iPop360</span>',
        props: ['class'],
    },
}))

function makeCategories(overrides: any[] = []) {
    return overrides.length ? overrides : [
        { id: 1, name: 'Italian', slug: 'italian', icon: null, cuisines: [] },
        { id: 2, name: 'Asian', slug: 'asian', icon: null, cuisines: [] },
    ]
}

interface MountOptions {
    categories?: any[]
    location?: { city: string | null; state: string | null }
    detectingLocation?: boolean
    stats?: { restaurants: number; cuisines: number; cities: number }
}

function mountComponent(options: MountOptions = {}) {
    return mount(HeroBanner, {
        props: {
            categories: options.categories ?? makeCategories(),
            location: options.location ?? { city: 'Austin', state: 'TX' },
            detectingLocation: options.detectingLocation ?? false,
            stats: options.stats ?? { restaurants: 39398, cuisines: 59, cities: 1484 },
        },
    })
}

describe('HeroBanner', () => {
    beforeEach(() => {
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('renders the root section', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('section').exists()).toBe(true)
    })

    it('does not render any top-nav links (nav moved to TopNav)', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('a[href="/leaderboard"]').exists()).toBe(false)
        expect(wrapper.find('a[href="/login"]').exists()).toBe(false)
        expect(wrapper.find('a[href="/favorites"]').exists()).toBe(false)
        expect(wrapper.find('a[href="/dashboard"]').exists()).toBe(false)
    })

    it('renders the BrandLogo component', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('.brand-logo-stub').exists()).toBe(true)
    })

    it('renders the CuisinePicker stub', () => {
        const wrapper = mountComponent()
        const picker = wrapper.find('[data-testid="cuisine-picker"]')
        expect(picker.exists()).toBe(true)
    })

    it('renders the LocationPicker stub', () => {
        const wrapper = mountComponent()
        const picker = wrapper.find('[data-testid="location-picker"]')
        expect(picker.exists()).toBe(true)
    })

    it('shows "Search" text on button when not detecting location', () => {
        const wrapper = mountComponent({ detectingLocation: false })
        expect(wrapper.text()).toContain('Search')
        expect(wrapper.text()).not.toContain('Detecting location')
    })

    it('shows detecting spinner and text when detectingLocation is true', () => {
        const wrapper = mountComponent({ detectingLocation: true })
        expect(wrapper.text()).toContain('Detecting location...')
        expect(wrapper.find('.animate-spin').exists()).toBe(true)
    })

    it('disables the search button when detectingLocation is true', () => {
        const wrapper = mountComponent({ detectingLocation: true })
        const button = wrapper.find('button')
        expect(button.attributes('disabled')).toBeDefined()
    })

    it('emits search when search button is clicked', async () => {
        const wrapper = mountComponent()
        const buttons = wrapper.findAll('button')
        const searchButton = buttons.find((b) => b.text() === 'Search')
        expect(searchButton).toBeDefined()
        await searchButton!.trigger('click')
        expect(wrapper.emitted('search')).toBeTruthy()
        expect(wrapper.emitted('search')).toHaveLength(1)
    })

    it('does not emit search when detectingLocation is true and button is clicked', async () => {
        const wrapper = mountComponent({ detectingLocation: true })
        const buttons = wrapper.findAll('button')
        const detectingButton = buttons.find((b) => b.text().includes('Detecting location'))
        expect(detectingButton).toBeDefined()
        await detectingButton!.trigger('click')
        expect(wrapper.emitted('search')).toBeFalsy()
    })

    it('renders dot indicators for each slide', () => {
        const wrapper = mountComponent()
        const allButtons = wrapper.findAll('button')
        const dotButtons = allButtons.filter((b) => b.attributes('aria-label')?.startsWith('Go to slide'))
        expect(dotButtons).toHaveLength(2)
    })

    it('sets the first dot as active by default', () => {
        const wrapper = mountComponent()
        const allButtons = wrapper.findAll('button')
        const dotButtons = allButtons.filter((b) => b.attributes('aria-label')?.startsWith('Go to slide'))
        expect(dotButtons[0].classes()).toContain('bg-white')
        expect(dotButtons[0].classes()).toContain('w-6')
        expect(dotButtons[1].classes()).not.toContain('bg-white')
    })

    it('renders photo attribution for the current slide', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Photo by Tester 1')
    })

    it('renders a play/pause toggle button', () => {
        const wrapper = mountComponent()
        const toggleButton = wrapper.find('button[aria-label="Pause slideshow"]')
        expect(toggleButton.exists()).toBe(true)
    })

    it('toggles play/pause on button click', async () => {
        const wrapper = mountComponent()
        const pauseButton = wrapper.find('button[aria-label="Pause slideshow"]')
        expect(pauseButton.exists()).toBe(true)
        await pauseButton.trigger('click')
        const resumeButton = wrapper.find('button[aria-label="Resume slideshow"]')
        expect(resumeButton.exists()).toBe(true)
    })

    it('renders the logo link as an anchor with aria-label iPop360 home', () => {
        const wrapper = mountComponent()
        const logoLink = wrapper.find('a[aria-label="iPop360 home"]')
        expect(logoLink.exists()).toBe(true)
        expect(logoLink.attributes('href')).toBe('/')
    })

    describe('stats row', () => {
        beforeEach(() => {
            Object.defineProperty(window, 'matchMedia', {
                value: vi.fn().mockReturnValue({ matches: true }),
                writable: true,
                configurable: true,
            })
        })

        afterEach(() => {
            vi.restoreAllMocks()
        })

        it('renders the three stat labels', async () => {
            const wrapper = mountComponent()
            await nextTick()
            expect(wrapper.text()).toContain('Restaurants')
            expect(wrapper.text()).toContain('Cuisines')
            expect(wrapper.text()).toContain('Cities')
        })

        it('renders stat values formatted with thousands separators', async () => {
            const wrapper = mountComponent({ stats: { restaurants: 39398, cuisines: 59, cities: 1484 } })
            await nextTick()
            expect(wrapper.text()).toContain('39,398')
            expect(wrapper.text()).toContain('59')
            expect(wrapper.text()).toContain('1,484')
        })

        it('renders zero stats without error', async () => {
            const wrapper = mountComponent({ stats: { restaurants: 0, cuisines: 0, cities: 0 } })
            await nextTick()
            expect(wrapper.text()).toContain('Restaurants')
        })

        it('applies the hero-stats-fade entrance class to the stats row', () => {
            const wrapper = mountComponent()
            const row = wrapper.find('.hero-stats-fade')
            expect(row.exists()).toBe(true)
        })

        it('marks the stats row as an accessible list with final-value aria-labels', () => {
            const wrapper = mountComponent({ stats: { restaurants: 39398, cuisines: 59, cities: 1484 } })
            const list = wrapper.find('.hero-stats-fade[role="list"]')
            expect(list.exists()).toBe(true)
            expect(list.attributes('aria-label')).toBe('Popularity statistics')
            const items = wrapper.findAll('[role="listitem"]')
            expect(items).toHaveLength(3)
            expect(items[0].attributes('aria-label')).toBe('39,398 Restaurants')
            expect(items[1].attributes('aria-label')).toBe('59 Cuisines')
            expect(items[2].attributes('aria-label')).toBe('1,484 Cities')
        })

        it('sizes the stats row to fit a 375px viewport (compact mobile, dramatic desktop)', () => {
            const wrapper = mountComponent()
            const items = wrapper.findAll('.hero-stats-fade .flex.flex-col')
            expect(items).toHaveLength(3)
            for (const item of items) {
                expect(item.classes()).toContain('px-3')
                expect(item.classes()).toContain('sm:px-10')
            }
            const numerals = wrapper.findAll('.tabular-nums')
            for (const numeral of numerals) {
                expect(numeral.classes()).toContain('text-2xl')
                expect(numeral.classes()).toContain('sm:text-4xl')
            }
        })
    })
})
