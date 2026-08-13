import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import AdminUsersIndex from '@/Pages/Admin/Users/Index.vue'

const mockRoute = vi.fn((name: string, params?: any) => {
    const routes: Record<string, string> = {
        'admin.users.index': '/admin/users',
    }
    if (routes[name]) return routes[name]
    if (name === 'admin.users.update') return `/admin/users/${params}`
    return '#'
})

const { mockUsePage, mockGet, mockPatch } = vi.hoisted(() => ({
    mockUsePage: vi.fn(),
    mockGet: vi.fn(),
    mockPatch: vi.fn(),
}))

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    router: {
        get: mockGet,
        patch: mockPatch,
    },
    usePage: mockUsePage,
}))

vi.mock('@lucide/vue', () => ({
    ShieldCheck: { template: '<svg data-testid="shield-check" />' },
    Users: { template: '<svg data-testid="users" />' },
    X: { template: '<svg data-testid="x" />' },
}))

const stubs = {
    AuthenticatedLayout: { template: '<div><slot name="header" /><slot /></div>' },
    Button: { template: '<button :type="type" :disabled="disabled"><slot /></button>', props: ['type', 'disabled', 'variant', 'size', 'asChild'] },
    Badge: { template: '<span :class="variant"><slot /></span>', props: ['variant'] },
}

interface UserRow {
    id: number
    name: string
    email: string
    role: string
    email_verified_at: string | null
    created_at: string | null
}

function makeUser(overrides: Partial<UserRow> = {}): UserRow {
    return {
        id: 1,
        name: 'Test User',
        email: 'test@example.com',
        role: 'user',
        email_verified_at: null,
        created_at: null,
        ...overrides,
    }
}

function mountComponent(propsOverrides: Record<string, any> = {}) {
    return mount(AdminUsersIndex, {
        props: {
            users: {
                data: [makeUser()],
                links: [],
                total: 1,
            },
            filter: null,
            roles: ['admin', 'editor', 'user'],
            ...propsOverrides,
        },
        global: {
            stubs,
            config: {
                globalProperties: {
                    route: mockRoute,
                },
            },
        },
    })
}

describe('Admin Users Index page', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        ;(globalThis as any).route = mockRoute
        mockUsePage.mockReturnValue({ props: { errors: {}, auth: { user: { id: 1 } } } })
        mockPatch.mockResolvedValue(undefined)
    })

    it('renders the page heading and total count', () => {
        const wrapper = mountComponent({
            users: { data: [makeUser(), makeUser({ id: 2 })], links: [], total: 2 },
        })
        expect(wrapper.text()).toContain('Users')
        expect(wrapper.text()).toContain('2 total')
    })

    it('renders filter buttons: All plus each role', () => {
        const wrapper = mountComponent()
        const buttonTexts = wrapper.findAll('button').map(b => b.text().trim()).filter(t => ['All', 'admin', 'editor', 'user'].includes(t))
        expect(buttonTexts).toEqual(['All', 'admin', 'editor', 'user'])
    })

    it('highlights "All" when filter is null', () => {
        const wrapper = mountComponent({ filter: null })
        const allBtn = wrapper.findAll('button').find(b => b.text().trim() === 'All')
        expect(allBtn?.classes()).toContain('text-primary')
    })

    it('highlights the active role filter', () => {
        const wrapper = mountComponent({ filter: 'editor' })
        const editorBtn = wrapper.findAll('button').find(b => b.text().trim() === 'editor')
        expect(editorBtn?.classes()).toContain('text-primary')
    })

    it('calls router.get when clicking a role filter', async () => {
        const wrapper = mountComponent()
        const editorBtn = wrapper.findAll('button').find(b => b.text().trim() === 'editor')
        await editorBtn!.trigger('click')
        expect(mockGet).toHaveBeenCalledWith('/admin/users', { role: 'editor' }, { preserveState: true })
    })

    it('renders user name, email, and role', () => {
        const wrapper = mountComponent({
            users: { data: [makeUser({ name: 'Jane Doe', email: 'jane@example.com', role: 'editor' })], links: [], total: 1 },
        })
        expect(wrapper.text()).toContain('Jane Doe')
        expect(wrapper.text()).toContain('jane@example.com')
        expect(wrapper.text()).toContain('editor')
    })

    it('renders the joined date for each user', () => {
        const wrapper = mountComponent({
            users: { data: [makeUser({ created_at: '2026-01-15T00:00:00.000000Z' })], links: [], total: 1 },
        })
        expect(wrapper.text()).toContain('Joined')
        expect(wrapper.text()).not.toContain('—')
    })

    it('renders an em dash for users with no joined date', () => {
        const wrapper = mountComponent({
            users: { data: [makeUser({ created_at: null })], links: [], total: 1 },
        })
        expect(wrapper.text()).toContain('—')
    })

    it('assigns badge variants by role', () => {
        mockUsePage.mockReturnValue({ props: { errors: {}, auth: { user: { id: 999 } } } })
        const wrapper = mountComponent({
            users: {
                data: [
                    makeUser({ id: 1, role: 'admin' }),
                    makeUser({ id: 2, role: 'editor' }),
                    makeUser({ id: 3, role: 'user' }),
                ],
                links: [],
                total: 3,
            },
        })
        const badges = wrapper.findAllComponents(stubs.Badge)
        expect(badges.map(b => b.props('variant'))).toEqual(['default', 'secondary', 'outline'])
    })

    it('shows a "You" badge only on the current user row', () => {
        const wrapper = mountComponent({
            users: {
                data: [makeUser({ id: 1, name: 'Self' }), makeUser({ id: 2, name: 'Other' })],
                links: [],
                total: 2,
            },
        })
        const rows = wrapper.findAll('tbody tr')
        expect(rows[0].text()).toContain('You')
        expect(rows[1].text()).not.toContain('You')
    })

    it('disables the role select for the current user row', () => {
        const wrapper = mountComponent({
            users: {
                data: [makeUser({ id: 1 }), makeUser({ id: 2 })],
                links: [],
                total: 2,
            },
        })
        const selects = wrapper.findAll('select')
        expect((selects[0].element as HTMLSelectElement).disabled).toBe(true)
        expect((selects[1].element as HTMLSelectElement).disabled).toBe(false)
    })

    it('does not call router.patch when the current user select changes', async () => {
        const wrapper = mountComponent({
            users: { data: [makeUser({ id: 1 })], links: [], total: 1 },
        })
        await wrapper.find('select').setValue('editor')
        expect(mockPatch).not.toHaveBeenCalled()
    })

    it('select reflects the user role', () => {
        const wrapper = mountComponent({
            users: { data: [makeUser({ role: 'admin' })], links: [], total: 1 },
        })
        const select = wrapper.find('select')
        expect((select.element as HTMLSelectElement).value).toBe('admin')
    })

    it('calls router.patch with the update route and new role on change', async () => {
        const wrapper = mountComponent({
            users: { data: [makeUser({ id: 42, email: 'target@example.com' })], links: [], total: 1 },
        })
        await wrapper.find('select').setValue('editor')
        expect(mockPatch).toHaveBeenCalledWith(
            '/admin/users/42',
            { role: 'editor' },
            expect.objectContaining({ preserveScroll: true }),
        )
    })

    it('sets success flash after a successful role update', async () => {
        mockPatch.mockImplementation((_url: string, _data: any, options: any) => {
            options?.onSuccess?.()
            return Promise.resolve()
        })
        const wrapper = mountComponent({
            users: { data: [makeUser({ id: 42, email: 'target@example.com' })], links: [], total: 1 },
        })
        await wrapper.find('select').setValue('editor')
        expect(wrapper.text()).toContain('Role for target@example.com updated to editor.')
    })

    it('displays the role validation error inline', () => {
        mockUsePage.mockReturnValue({
            props: { errors: { role: 'You cannot change your own role.' }, auth: { user: { id: 1 } } },
        })
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('You cannot change your own role.')
    })

    it('does not show the role error when absent', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('.bg-red-50').exists()).toBe(false)
    })

    it('clears a stale success flash when a role update errors', async () => {
        mockUsePage.mockReturnValue({
            props: { flash: { success: 'Role updated.' }, errors: {}, auth: { user: { id: 1 } } },
        })
        mockPatch.mockImplementation((_url: string, _data: any, options: any) => {
            options?.onError?.()
            return Promise.resolve()
        })
        const wrapper = mountComponent({
            users: { data: [makeUser({ id: 42 })], links: [], total: 1 },
        })
        expect(wrapper.text()).toContain('Role updated.')
        await wrapper.find('select').setValue('editor')
        expect(wrapper.find('.bg-emerald-50').exists()).toBe(false)
    })

    it('shows empty state when there are no users', () => {
        const wrapper = mountComponent({
            users: { data: [], links: [], total: 0 },
        })
        expect(wrapper.text()).toContain('No users found.')
    })

    it('does not show empty state when users exist', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).not.toContain('No users found.')
    })

    it('shows pagination when links.length > 3', () => {
        const wrapper = mountComponent({
            users: {
                data: [makeUser()],
                links: [
                    { url: null, label: 'Previous', active: false },
                    { url: '/admin/users?page=1', label: '1', active: true },
                    { url: '/admin/users?page=2', label: '2', active: false },
                    { url: '/admin/users?page=2', label: 'Next', active: false },
                ],
                total: 25,
            },
        })
        expect(wrapper.find('nav').exists()).toBe(true)
    })
})
