import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import AppLayout from '@/Layouts/AppLayout.vue'

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
    AppFooter: { template: '<footer />' },
    Badge: { template: '<span><slot /></span>' },
}

function mountLayout(role: 'admin' | 'editor' | 'user' | null) {
    const user = role ? { id: 1, name: 'Test User', email: 'test@example.com', role } : null
    mockUsePage.mockReturnValue({ props: { auth: { user } } })
    return mount(AppLayout, {
        global: {
            stubs,
            mocks: { $page: { props: { auth: { user } } } },
            config: { globalProperties: { route: mockRoute } },
        },
    })
}

describe('AppLayout nav', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mockUsePage.mockReturnValue({ props: { auth: { user: null } } })
    })

    it('shows Manage Blog link for admin users', () => {
        const wrapper = mountLayout('admin')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBeGreaterThan(0)
    })

    it('shows Manage Blog link for editor users', () => {
        const wrapper = mountLayout('editor')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBeGreaterThan(0)
    })

    it('hides Manage Blog link for standard users', () => {
        const wrapper = mountLayout('user')
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBe(0)
    })

    it('hides Manage Blog link for unauthenticated visitors', () => {
        const wrapper = mountLayout(null)
        expect(wrapper.findAll('a[href="/admin/blog"]').length).toBe(0)
    })

    it('shows Login link for unauthenticated visitors', () => {
        const wrapper = mountLayout(null)
        expect(wrapper.findAll('a[href="/login"]').length).toBeGreaterThan(0)
    })

    it('always shows public Blog link', () => {
        const wrapper = mountLayout(null)
        expect(wrapper.findAll('a[href="/blog"]').length).toBeGreaterThan(0)
    })

    it('always shows Leaderboard link', () => {
        const wrapper = mountLayout(null)
        expect(wrapper.findAll('a[href="/leaderboard"]').length).toBeGreaterThan(0)
    })
})
