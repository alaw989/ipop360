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

    it('keeps the mobile menu collapsed by default', () => {
        const wrapper = mountNav(null)
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(false)
    })

    it('opens the mobile menu when the toggle is clicked', async () => {
        const wrapper = mountNav(null)
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        const menu = wrapper.find('[data-testid="mobile-menu"]')
        expect(menu.exists()).toBe(true)
        expect(menu.findAll('a[href="/login"]').length).toBeGreaterThan(0)
    })

    it('renders auth-dependent links inside the mobile menu', async () => {
        const wrapper = mountNav('user')
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        const menu = wrapper.find('[data-testid="mobile-menu"]')
        expect(menu.findAll('a[href="/favorites"]').length).toBeGreaterThan(0)
        expect(menu.findAll('a[href="/dashboard"]').length).toBeGreaterThan(0)
    })

    it('renders the Manage Blog link inside the mobile menu for admins', async () => {
        const wrapper = mountNav('admin')
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        const menu = wrapper.find('[data-testid="mobile-menu"]')
        expect(menu.findAll('a[href="/admin/blog"]').length).toBeGreaterThan(0)
    })

    it('closes the mobile menu when the toggle is clicked again', async () => {
        const wrapper = mountNav(null)
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(false)
    })

    it('closes the mobile menu when a nav link is clicked', async () => {
        const wrapper = mountNav(null)
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(true)
        await wrapper.find('[data-testid="mobile-menu"] a[href="/login"]').trigger('click')
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(false)
    })

    it('closes the mobile menu when the Escape key is pressed', async () => {
        const wrapper = mountNav(null)
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(true)
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(false)
    })

    it('keeps the mobile menu open when Escape is pressed while closed', async () => {
        const wrapper = mountNav(null)
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(false)
    })

    it('closes the mobile menu when clicking outside the nav', async () => {
        const wrapper = mountNav(null)
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(true)
        document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(false)
    })

    it('does not close the mobile menu when clicking inside it', async () => {
        const wrapper = mountNav(null)
        await wrapper.find('[data-testid="menu-toggle"]').trigger('click')
        const menu = wrapper.find('[data-testid="mobile-menu"]')
        expect(menu.exists()).toBe(true)
        menu.trigger('pointerdown')
        await wrapper.vm.$nextTick()
        expect(wrapper.find('[data-testid="mobile-menu"]').exists()).toBe(true)
    })
})