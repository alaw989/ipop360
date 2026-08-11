import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

const { mockUsePage } = vi.hoisted(() => ({
    mockUsePage: vi.fn(() => ({
        props: { auth: { user: { id: 1, name: 'Test User', email: 'test@example.com', role: 'user' } } },
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

const mockRoute = vi.fn((name?: string, params?: unknown): any => {
    if (name === undefined) {
        return { current: () => true }
    }
    const paths: Record<string, string> = {
        dashboard: '/dashboard',
        'admin.dashboard': '/admin',
        'admin.blog.index': '/admin/blog',
        'profile.edit': '/profile',
        logout: '/logout',
    }
    return paths[name] ?? '#'
})

const stubs = {
    ApplicationLogo: { template: '<svg data-testid="logo" />' },
    Dropdown: { template: '<div class="dropdown"><slot name="trigger" /><slot name="content" /></div>' },
    DropdownLink: { template: '<a :href="href" class="dropdown-link"><slot /></a>', props: ['href'] },
    NavLink: { template: '<a :href="href" class="nav-link"><slot /></a>', props: ['href', 'active'] },
    ResponsiveNavLink: { template: '<a :href="href" class="responsive-nav-link"><slot /></a>', props: ['href', 'active'] },
}

function mountLayout(role: 'admin' | 'editor' | 'user') {
    const user = { id: 1, name: 'Test User', email: 'test@example.com', role }
    mockUsePage.mockReturnValue({ props: { auth: { user } } })
    return mount(AuthenticatedLayout, {
        global: {
            stubs,
            mocks: { $page: { props: { auth: { user } } } },
            config: { globalProperties: { route: mockRoute } },
        },
    })
}

describe('AuthenticatedLayout nav', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mockUsePage.mockReturnValue({
            props: { auth: { user: { id: 1, name: 'Test User', email: 'test@example.com', role: 'user' } } },
        })
    })

    it('shows the Blog link for editor users', () => {
        const wrapper = mountLayout('editor')
        const blogLinks = wrapper.findAll('a[href="/admin/blog"]')
        expect(blogLinks.length).toBeGreaterThan(0)
    })

    it('hides the Blog link for standard users', () => {
        const wrapper = mountLayout('user')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBe(0)
    })

    it('shows the Blog link for admin users', () => {
        const wrapper = mountLayout('admin')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBeGreaterThan(0)
    })

    it('shows the Admin link only for admin users', () => {
        expect(mountLayout('admin').findAll('a[href="/admin"]').length).toBeGreaterThan(0)
        expect(mountLayout('editor').findAll('a[href="/admin"]').length).toBe(0)
        expect(mountLayout('user').findAll('a[href="/admin"]').length).toBe(0)
    })

    it('always shows Dashboard and Profile links', () => {
        const wrapper = mountLayout('user')
        expect(wrapper.findAll('a[href="/dashboard"]').length).toBeGreaterThan(0)
        expect(wrapper.findAll('a[href="/profile"]').length).toBeGreaterThan(0)
    })
})
