import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import HeroBanner from '@/Components/HeroBanner.vue'

vi.mock('@/lib/slideshow', () => ({
    slides: [
        { id: 'photo-a', attribution: 'Photo by Tester 1' },
        { id: 'photo-b', attribution: 'Photo by Tester 2' },
    ],
    slideSources: (slide: { id: string }) => ({
        phone: `/img/${slide.id}-600.jpg 600w`,
        wide: `/img/${slide.id}-1600.jpg 1600w`,
        fallback: `/img/${slide.id}.jpg`,
    }),
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
}

function mountComponent(options: MountOptions = {}) {
    return mount(HeroBanner, {
        props: {
            categories: options.categories ?? makeCategories(),
            location: options.location ?? { city: 'Austin', state: 'TX' },
            detectingLocation: options.detectingLocation ?? false,
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

    it('does not repeat the logo (the header already shows it)', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('.brand-logo-stub').exists()).toBe(false)
        expect(wrapper.find('a[aria-label="iPop360 home"]').exists()).toBe(false)
    })

    it('leads with a plain headline', () => {
        const wrapper = mountComponent()
        expect(wrapper.get('h1').text()).toBe('Find the most popular restaurants near you')
    })

    it('shows the two-part search with the cuisine and place pickers as fields', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('form[role="search"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="search-what"] [data-testid="cuisine-picker"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="search-where"] [data-testid="location-picker"]').exists()).toBe(true)
    })

    it('loads only the first photo with the page, and the rest later', async () => {
        const wrapper = mountComponent()
        expect(wrapper.findAll('picture')).toHaveLength(1)
        expect(wrapper.find('img').attributes('fetchpriority')).toBe('high')
        vi.advanceTimersByTime(4000)
        await wrapper.vm.$nextTick()
        expect(wrapper.findAll('picture')).toHaveLength(2)
    })

    it('serves phones their own crop of the photo', () => {
        const wrapper = mountComponent()
        const phone = wrapper.find('source[media="(max-width: 767px)"]')
        expect(phone.attributes('srcset')).toContain('photo-a-600.jpg')
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

    it('shows a Search button', () => {
        const wrapper = mountComponent({ detectingLocation: false })
        expect(wrapper.get('[data-testid="search-submit"]').text()).toContain('Search')
    })

    it('disables the search button while finding the location', () => {
        const wrapper = mountComponent({ detectingLocation: true })
        expect(wrapper.get('[data-testid="search-submit"]').attributes('disabled')).toBeDefined()
    })

    it('emits search when the search form is submitted', async () => {
        const wrapper = mountComponent()
        await wrapper.get('form').trigger('submit')
        expect(wrapper.emitted('search')).toHaveLength(1)
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
        const firstDot = dotButtons[0].find('span')
        const secondDot = dotButtons[1].find('span')
        expect(firstDot.classes()).toContain('bg-white')
        expect(firstDot.classes()).toContain('w-6')
        expect(secondDot.classes()).not.toContain('bg-white')
    })

    it('gives each dot a touch-friendly target size', () => {
        const wrapper = mountComponent()
        const allButtons = wrapper.findAll('button')
        const dotButtons = allButtons.filter((b) => b.attributes('aria-label')?.startsWith('Go to slide'))
        for (const dot of dotButtons) {
            expect(dot.classes()).toContain('h-7')
            expect(dot.classes()).toContain('w-7')
        }
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
})
