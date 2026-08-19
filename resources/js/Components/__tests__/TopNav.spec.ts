import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import TopNav from '@/Components/TopNav.vue'

const { mockUsePage } = vi.hoisted(() => ({
    mockUsePage: vi.fn(() => ({
        props: { auth: { user: null } },
    })),
}))

const mockRoute = vi.fn((name?: string, params?: unknown): any => {
    if (name === undefined) {
        return { current: () => true }
    }
    const paths: Record<string, string> = {
        'admin.blog.index': '/admin/blog',
    }
    return paths[name] ?? '#'
})

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...(actual as any),
        usePage: mockUsePage,
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

const stubs = {
    BrandLogo: { template: '<svg data-testid="logo" />' },
    Badge: { template: '<span><slot /></span>' },
    Sheet: { template: '<div><slot /></div>' },
    SheetTrigger: { template: '<div><slot /></div>' },
    SheetContent: {
        props: ['side'],
        template: '<div :data-side="side" data-testid="mobile-menu-sheet"><slot /></div>',
    },
    SheetTitle: { template: '<h2 data-testid="sheet-title"><slot /></h2>' },
    SheetDescription: { template: '<p data-testid="sheet-description"><slot /></p>' },
}

function mountNav(role: 'admin' | 'editor' | 'user' | null, sticky = true, transparent = false) {
    const user = role ? { id: 1, name: 'Test User', email: 'test@example.com', role } : null
    mockUsePage.mockReturnValue({ props: { auth: { user } } })
    return mount(TopNav, {
        props: { sticky, transparent },
        global: {
            stubs,
            mocks: { $page: { props: { auth: { user } } } },
            config: { globalProperties: { route: mockRoute } },
        },
    })
}

describe('TopNav', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mockUsePage.mockReturnValue({ props: { auth: { user: null } } })
    })

    it('shows Manage Blog link for admin users', () => {
        const wrapper = mountNav('admin')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBeGreaterThan(0)
    })

    it('shows Manage Blog link for editor users', () => {
        const wrapper = mountNav('editor')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBeGreaterThan(0)
    })

    it('hides Manage Blog link for standard users', () => {
        const wrapper = mountNav('user')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBe(0)
    })

    it('hides Manage Blog link for unauthenticated visitors', () => {
        const wrapper = mountNav(null)
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBe(0)
    })

    it('always shows public Blog link', () => {
        const wrapper = mountNav(null)
        expect(wrapper.findAll('a[href="/blog"]').length).toBeGreaterThan(0)
    })

    it('always shows Leaderboard link', () => {
        const wrapper = mountNav(null)
        expect(wrapper.findAll('a[href="/leaderboard"]').length).toBeGreaterThan(0)
    })

    it('always shows Browse link', () => {
        const wrapper = mountNav(null)
        expect(wrapper.findAll('a[href="/restaurants"]').length).toBeGreaterThan(0)
    })

    it('shows Login link for unauthenticated visitors', () => {
        const wrapper = mountNav(null)
        expect(wrapper.findAll('a[href="/login"]').length).toBeGreaterThan(0)
    })

    it('shows Favorites and Dashboard links for authenticated users', () => {
        const wrapper = mountNav('user')
        expect(wrapper.findAll('a[href="/favorites"]').length).toBeGreaterThan(0)
        expect(wrapper.findAll('a[href="/dashboard"]').length).toBeGreaterThan(0)
    })

    it('is sticky by default', () => {
        const wrapper = mountNav(null, true)
        expect(wrapper.find('nav').classes()).toContain('sticky')
    })

    it('is not sticky when the sticky prop is false', () => {
        const wrapper = mountNav(null, false)
        expect(wrapper.find('nav').classes()).not.toContain('sticky')
    })

    it('is opaque by default (card background)', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('nav').classes()).toContain('bg-card/80')
    })

    it('is transparent when the transparent prop is true', () => {
        const wrapper = mountNav(null, true, true)
        expect(wrapper.find('nav').classes()).not.toContain('bg-card/80')
        expect(wrapper.find('nav').classes()).not.toContain('border-border')
    })

    it('uses light link styling in transparent mode', () => {
        const wrapper = mountNav(null, true, true)
        const browse = wrapper.findAll('a[href="/restaurants"]')[0]
        expect(browse.classes()).toContain('text-white/80')
    })

    it('renders a mobile menu toggle button', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('[data-testid="menu-toggle"]').exists()).toBe(true)
    })

    it('hides the menu toggle on desktop (md:hidden)', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('[data-testid="menu-toggle"]').classes()).toContain('md:hidden')
    })

    it('renders the mobile menu as a right-side drawer', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('[data-testid="mobile-menu-sheet"]').attributes('data-side')).toBe('right')
    })

    it('renders public links inside the mobile drawer', () => {
        const wrapper = mountNav(null)
        const menu = wrapper.find('[data-testid="mobile-menu"]')
        expect(menu.findAll('a[href="/restaurants"]').length).toBeGreaterThan(0)
        expect(menu.findAll('a[href="/leaderboard"]').length).toBeGreaterThan(0)
        expect(menu.findAll('a[href="/blog"]').length).toBeGreaterThan(0)
        expect(menu.findAll('a[href="/login"]').length).toBeGreaterThan(0)
    })

    it('renders auth-dependent links inside the mobile drawer', () => {
        const wrapper = mountNav('user')
        const menu = wrapper.find('[data-testid="mobile-menu"]')
        expect(menu.findAll('a[href="/favorites"]').length).toBeGreaterThan(0)
        expect(menu.findAll('a[href="/dashboard"]').length).toBeGreaterThan(0)
    })

    it('renders the Manage Blog link inside the mobile drawer for admins', () => {
        const wrapper = mountNav('admin')
        const menu = wrapper.find('[data-testid="mobile-menu"]')
        expect(menu.findAll('a[href="/admin/blog"]').length).toBeGreaterThan(0)
    })

    it('renders a close button inside the mobile drawer', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('[data-testid="mobile-menu-close"]').exists()).toBe(true)
    })

    it('renders an accessible title in the mobile drawer', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('[data-testid="sheet-title"]').text()).toBe('Menu')
    })

    it('renders an accessible description in the mobile drawer', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('[data-testid="sheet-description"]').exists()).toBe(true)
    })
})
