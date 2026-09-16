import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const { mockUsePage } = vi.hoisted(() => ({
    mockUsePage: vi.fn(() => ({
        url: '/',
        props: { auth: { user: null } },
    })),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...(actual as any),
        usePage: mockUsePage,
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

import BottomTabBar from '@/Components/BottomTabBar.vue'

function mountBar(url = '/', user: { id: number } | null = null) {
    mockUsePage.mockReturnValue({ url, props: { auth: { user } } })
    return mount(BottomTabBar)
}

function tabLinks(wrapper: ReturnType<typeof mountBar>) {
    return wrapper.findAll('a')
}

describe('BottomTabBar', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mockUsePage.mockReturnValue({ url: '/', props: { auth: { user: null } } })
    })

    it('renders the five primary destinations', () => {
        const wrapper = mountBar()
        const labels = tabLinks(wrapper).map((a) => a.text())
        expect(labels).toEqual(['Search', 'Browse', 'Leaderboard', 'Saved', 'Account'])
    })

    it('links the public destinations', () => {
        const wrapper = mountBar()
        const hrefs = tabLinks(wrapper).map((a) => a.attributes('href'))
        expect(hrefs).toEqual(['/search', '/restaurants', '/leaderboard', '/login', '/login'])
    })

    it('is a fixed, bottom-anchored, mobile-only bar', () => {
        const wrapper = mountBar()
        const nav = wrapper.find('[data-testid="bottom-tab-bar"]')
        const classes = nav.classes()
        expect(classes).toContain('fixed')
        expect(classes).toContain('inset-x-0')
        expect(classes).toContain('bottom-0')
        expect(classes).toContain('md:hidden')
    })

    it('reserves safe-area bottom padding for notched devices', () => {
        const wrapper = mountBar()
        expect(wrapper.find('[data-testid="bottom-tab-bar"]').classes()).toContain('pb-[env(safe-area-inset-bottom)]')
    })

    it('gives every tab a >=44px touch target', () => {
        const wrapper = mountBar()
        tabLinks(wrapper).forEach((a) => expect(a.classes()).toContain('min-h-11'))
    })

    it('marks the active tab with aria-current="page"', () => {
        const wrapper = mountBar('/search?city=Austin')
        const active = tabLinks(wrapper).find((a) => a.attributes('aria-current') === 'page')
        expect(active?.text()).toBe('Search')
    })

    it('treats the browse index and detail pages as the Browse tab', () => {
        for (const url of ['/restaurants', '/restaurants/some-venue']) {
            const wrapper = mountBar(url)
            const active = tabLinks(wrapper).find((a) => a.attributes('aria-current') === 'page')
            expect(active?.text()).toBe('Browse')
        }
    })

    it('marks no tab active on an unrelated page', () => {
        const wrapper = mountBar('/blog')
        expect(tabLinks(wrapper).some((a) => a.attributes('aria-current') === 'page')).toBe(false)
    })

    it('routes auth-gated tabs to /login when signed out', () => {
        const wrapper = mountBar('/', null)
        const hrefs = tabLinks(wrapper).map((a) => a.attributes('href'))
        expect(hrefs.slice(3)).toEqual(['/login', '/login'])
    })

    it('links auth-gated tabs to their destinations when signed in', () => {
        const wrapper = mountBar('/', { id: 1 })
        const links = tabLinks(wrapper)
        expect(links[3].attributes('href')).toBe('/favorites')
        expect(links[4].attributes('href')).toBe('/dashboard')
    })
})
