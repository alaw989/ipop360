import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import AdminBlogIndex from '@/Pages/Admin/Blog/Index.vue'

const mockRoute = vi.fn((name: string, params?: any) => {
    const routes: Record<string, string> = {
        'admin.blog.index': '/admin/blog',
        'admin.blog.create': '/admin/blog/create',
    }
    if (routes[name]) return routes[name]
    if (name === 'admin.blog.edit') return `/admin/blog/${params}/edit`
    if (name === 'admin.blog.destroy') return `/admin/blog/${params}`
    return '#'
})

const { mockUsePage } = vi.hoisted(() => ({
    mockUsePage: vi.fn(() => ({ props: {} })),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Head: { template: '<div />' },
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
        router: {
            get: vi.fn(),
            delete: vi.fn(),
        },
        usePage: mockUsePage,
    }
})

vi.mock('@lucide/vue', () => ({
    FileText: { template: '<svg data-testid="file-text" />' },
    Calendar: { template: '<svg data-testid="calendar" />' },
    Eye: { template: '<svg data-testid="eye" />' },
    Pencil: { template: '<svg data-testid="pencil" />' },
    Trash2: { template: '<svg data-testid="trash-2" />' },
    Plus: { template: '<svg data-testid="plus" />' },
    X: { template: '<svg data-testid="x" />' },
}))

const stubs = {
    AuthenticatedLayout: { template: '<div><slot name="header" /><slot /></div>' },
    Button: { template: '<button :type="type" :disabled="disabled"><slot /></button>', props: ['type', 'disabled', 'variant', 'size', 'asChild'] },
    Badge: { template: '<span :class="variant"><slot /></span>', props: ['variant'] },
}

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    status: string
    featured_image: string | null
    published_at: string | null
    author: { name: string } | null
}

function makePost(overrides: Partial<BlogPost> = {}): BlogPost {
    return {
        id: 1,
        title: 'Test Blog Post',
        slug: 'test-blog-post',
        excerpt: 'Test excerpt',
        status: 'published',
        featured_image: null,
        published_at: '2025-06-15T12:00:00.000000Z',
        author: { name: 'Admin User' },
        ...overrides,
    }
}

function mountComponent(propsOverrides: Record<string, any> = {}) {
    return mount(AdminBlogIndex, {
        props: {
            posts: {
                data: [makePost()],
                links: [],
                total: 1,
            },
            filter: null,
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

describe('Admin Blog Index page', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('renders the page heading', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Blog Posts')
    })

    it('renders a link to create a new post', () => {
        const wrapper = mountComponent()
        const link = wrapper.find('a[href="/admin/blog/create"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('New Post')
    })

    it('renders filter buttons: All, Published, Drafts', () => {
        const wrapper = mountComponent()
        const buttons = wrapper.findAll('button')
        const buttonTexts = buttons.map(b => b.text().trim()).filter(t => ['All', 'Published', 'Drafts'].includes(t))
        expect(buttonTexts).toEqual(['All', 'Published', 'Drafts'])
    })

    it('highlights the active filter button', () => {
        const wrapper = mountComponent({ filter: 'published' })
        const publishedBtn = wrapper.findAll('button').find(b => b.text().trim() === 'Published')
        expect(publishedBtn?.classes()).toContain('text-primary')
    })

    it('highlights "All" when filter is null', () => {
        const wrapper = mountComponent({ filter: null })
        const allBtn = wrapper.findAll('button').find(b => b.text().trim() === 'All')
        expect(allBtn?.classes()).toContain('text-primary')
    })

    it('renders post titles in the table', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ id: 1, title: 'How to Cook Pasta' }), makePost({ id: 2, title: 'Best Pizza Spots' })],
                links: [],
                total: 2,
            },
        })
        expect(wrapper.text()).toContain('How to Cook Pasta')
        expect(wrapper.text()).toContain('Best Pizza Spots')
    })

    it('renders author name for each post', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ author: { name: 'Jane Doe' } })],
                links: [],
                total: 1,
            },
        })
        expect(wrapper.text()).toContain('Jane Doe')
    })

    it('renders em dash when author is null', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ author: null })],
                links: [],
                total: 1,
            },
        })
        expect(wrapper.text()).toContain('—')
    })

    it('renders status badges with correct variant', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ status: 'draft' })],
                links: [],
                total: 1,
            },
        })
        expect(wrapper.text()).toContain('draft')
        const badges = wrapper.findAllComponents(stubs.Badge)
        expect(badges[0].props('variant')).toBe('secondary')
    })

    it('renders formatted published_at date', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ published_at: '2025-01-15T12:00:00.000000Z' })],
                links: [],
                total: 1,
            },
        })
        expect(wrapper.text()).toContain('Jan 15, 2025')
    })

    it('renders em dash when published_at is null', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ published_at: null })],
                links: [],
                total: 1,
            },
        })
        expect(wrapper.text()).toContain('—')
    })

    it('renders View link only for published posts', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ status: 'published', slug: 'my-post' })],
                links: [],
                total: 1,
            },
        })
        const viewLink = wrapper.find('a[href="/blog/my-post"]')
        expect(viewLink.exists()).toBe(true)
    })

    it('does not render View link for draft posts', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ status: 'draft', slug: 'my-draft' })],
                links: [],
                total: 1,
            },
        })
        const viewLink = wrapper.find('a[href="/blog/my-draft"]')
        expect(viewLink.exists()).toBe(false)
    })

    it('renders an Edit link for each post', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost({ id: 42 })],
                links: [],
                total: 1,
            },
        })
        const editLink = wrapper.find('a[href="/admin/blog/42/edit"]')
        expect(editLink.exists()).toBe(true)
    })

    it('renders a Delete button for each post', () => {
        const wrapper = mountComponent()
        const deleteBtn = wrapper.find('button[title="Delete"]')
        expect(deleteBtn.exists()).toBe(true)
    })

    it('shows empty state when no posts', () => {
        const wrapper = mountComponent({
            posts: {
                data: [],
                links: [],
                total: 0,
            },
        })
        expect(wrapper.text()).toContain('No blog posts found.')
    })

    it('does not show empty state when posts exist', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).not.toContain('No blog posts found.')
    })

    it('shows pagination when links.length > 3', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost()],
                links: [
                    { url: null, label: 'Previous', active: false },
                    { url: '/admin/blog?page=1', label: '1', active: true },
                    { url: '/admin/blog?page=2', label: '2', active: false },
                    { url: '/admin/blog?page=2', label: 'Next', active: false },
                ],
                total: 25,
            },
        })
        expect(wrapper.find('nav').exists()).toBe(true)
    })

    it('does not show pagination when links.length <= 3', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost()],
                links: [
                    { url: null, label: 'Previous', active: false },
                    { url: '/admin/blog?page=1', label: '1', active: true },
                    { url: null, label: 'Next', active: false },
                ],
                total: 1,
            },
        })
        expect(wrapper.find('nav').exists()).toBe(false)
    })

    it('renders disabled pagination links as spans', () => {
        const wrapper = mountComponent({
            posts: {
                data: [makePost()],
                links: [
                    { url: null, label: '...', active: false },
                    { url: '/admin/blog?page=1', label: '1', active: true },
                    { url: '/admin/blog?page=2', label: '2', active: false },
                    { url: '/admin/blog?page=2', label: 'Next', active: false },
                ],
                total: 25,
            },
        })
        const spans = wrapper.findAll('span.px-3')
        expect(spans.some(s => s.text() === '...')).toBe(true)
    })

    it('shows success flash message when present', () => {
        mockUsePage.mockReturnValue({
            props: {
                flash: { success: 'Post created successfully!' },
            },
        })
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Post created successfully!')
    })

    it('does not show flash message when absent', () => {
        mockUsePage.mockReturnValue({ props: {} })
        const wrapper = mountComponent()
        expect(wrapper.find('.bg-emerald-50').exists()).toBe(false)
    })
})
