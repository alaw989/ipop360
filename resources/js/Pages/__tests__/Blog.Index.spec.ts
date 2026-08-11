import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BlogIndex from '@/Pages/Blog/Index.vue'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Head: { template: '<div />' },
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

vi.mock('@/composables/useSeo', () => ({
    useSeo: vi.fn((options: any) => ({
        title: options.title,
        description: options.description,
        canonical: options.url || '',
        noindex: false,
        ogTitle: options.title,
        ogDescription: options.description,
        ogType: 'website',
        ogUrl: options.url || '',
        ogSiteName: 'iPop360',
        ogImage: '/img/ipop360-og.png',
        ogImageAlt: 'iPop360 logo',
        twitterCard: 'summary',
        twitterTitle: options.title,
        twitterDescription: options.description,
        twitterImage: '/img/ipop360-og.png',
    })),
}))

vi.mock('@/composables/useBaseUrl', () => ({
    useBaseUrl: vi.fn(() => ({ value: 'http://localhost' })),
}))

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SeoMeta: { template: '<div />' },
}

function makeBlogPost(overrides: Record<string, any> = {}) {
    return {
        id: 1,
        title: 'Test Post',
        slug: 'test-post',
        excerpt: 'Test excerpt text.',
        featured_image: null,
        published_at: '2025-01-15T00:00:00.000000Z',
        author: { name: 'Test Author' },
        ...overrides,
    }
}

function mountBlogIndex(propsOverrides: Record<string, any> = {}) {
    return mount(BlogIndex, {
        props: {
            posts: {
                data: [makeBlogPost()],
                links: [],
            },
            categories: [],
            filters: { category: null, search: null },
            ...propsOverrides,
        },
        global: { stubs },
    })
}

describe('Blog Index page', () => {
    it('renders the page heading', () => {
        const wrapper = mountBlogIndex()
        expect(wrapper.text()).toContain('Blog')
    })

    it('renders the subtitle', () => {
        const wrapper = mountBlogIndex()
        expect(wrapper.text()).toContain('Restaurant insights, food trends, and dining guides.')
    })

    it('renders post cards when posts exist', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [
                    makeBlogPost({ id: 1, title: 'First Post', excerpt: 'First excerpt.' }),
                    makeBlogPost({ id: 2, title: 'Second Post', slug: 'second-post' }),
                ],
                links: [],
            },
        })
        expect(wrapper.text()).toContain('First Post')
        expect(wrapper.text()).toContain('First excerpt.')
        expect(wrapper.text()).toContain('Second Post')
    })

    it('shows formatted date on post cards', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [makeBlogPost({ published_at: '2025-03-10T12:00:00.000000Z' })],
                links: [],
            },
        })
        expect(wrapper.text()).toContain('March 10, 2025')
    })

    it('shows empty state when no posts', () => {
        const wrapper = mountBlogIndex({
            posts: { data: [], links: [] },
        })
        expect(wrapper.text()).toContain('No articles yet — check back soon.')
    })

    it('does not show empty state when posts exist', () => {
        const wrapper = mountBlogIndex()
        expect(wrapper.text()).not.toContain('No articles yet — check back soon.')
    })

    it('renders post links to /blog/:slug', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [makeBlogPost({ slug: 'my-article' })],
                links: [],
            },
        })
        const link = wrapper.find('a[href="/blog/my-article"]')
        expect(link.exists()).toBe(true)
    })

    it('shows pagination when links.length > 3', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [makeBlogPost()],
                links: [
                    { url: null, label: 'Previous', active: false },
                    { url: '/blog?page=1', label: '1', active: true },
                    { url: '/blog?page=2', label: '2', active: false },
                    { url: '/blog?page=2', label: 'Next', active: false },
                ],
            },
        })
        const nav = wrapper.find('nav')
        expect(nav.exists()).toBe(true)
    })

    it('does not show pagination when links.length <= 3', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [makeBlogPost()],
                links: [
                    { url: null, label: 'Previous', active: false },
                    { url: '/blog?page=1', label: '1', active: true },
                    { url: null, label: 'Next', active: false },
                ],
            },
        })
        expect(wrapper.find('nav').exists()).toBe(false)
    })

    it('renders disabled pagination links as spans', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [makeBlogPost()],
                links: [
                    { url: null, label: '...', active: false },
                    { url: '/blog?page=1', label: '1', active: true },
                    { url: '/blog?page=2', label: '2', active: false },
                    { url: '/blog?page=2', label: 'Next', active: false },
                ],
            },
        })
        const spans = wrapper.findAll('span.px-3')
        expect(spans).toHaveLength(1)
        expect(spans[0].text()).toBe('...')
    })

    it('groups posts by month and shows month headers', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [
                    makeBlogPost({ id: 1, title: 'Jan Post', published_at: '2025-01-10T00:00:00.000000Z' }),
                    makeBlogPost({ id: 2, title: 'Feb Post', published_at: '2025-02-15T00:00:00.000000Z' }),
                ],
                links: [],
            },
        })
        const sections = wrapper.findAll('section')
        expect(sections).toHaveLength(2)
        expect(sections[0].text()).toContain('January 2025')
        expect(sections[0].text()).toContain('Jan Post')
        expect(sections[1].text()).toContain('February 2025')
        expect(sections[1].text()).toContain('Feb Post')
    })

    it('merges posts from the same month into one group', () => {
        const wrapper = mountBlogIndex({
            posts: {
                data: [
                    makeBlogPost({ id: 1, title: 'First Jan Post', published_at: '2025-01-10T00:00:00.000000Z' }),
                    makeBlogPost({ id: 2, title: 'Second Jan Post', published_at: '2025-01-20T00:00:00.000000Z' }),
                ],
                links: [],
            },
        })
        const sections = wrapper.findAll('section')
        expect(sections).toHaveLength(1)
        expect(sections[0].text()).toContain('January 2025')
        expect(sections[0].text()).toContain('First Jan Post')
        expect(sections[0].text()).toContain('Second Jan Post')
    })

    it('does not render category nav when categories is empty', () => {
        const wrapper = mountBlogIndex({ categories: [] })
        expect(wrapper.find('nav.flex-wrap').exists()).toBe(false)
    })

    it('renders category pills when categories are provided', () => {
        const wrapper = mountBlogIndex({ categories: ['tech', 'food'] })
        const pills = wrapper.findAll('nav.flex-wrap a')
        expect(pills).toHaveLength(3) // All + 2 categories
        expect(pills[0].text()).toBe('All')
        expect(pills[1].text()).toBe('tech')
        expect(pills[2].text()).toBe('food')
    })

    it('highlights the active category pill', () => {
        const wrapper = mountBlogIndex({
            categories: ['tech', 'food'],
            filters: { category: 'tech' },
        })
        const pills = wrapper.findAll('nav.flex-wrap a')
        expect(pills[1].classes()).toContain('bg-primary')
        expect(pills[2].classes()).not.toContain('bg-primary')
    })

    it('highlights "All" pill when no category is selected', () => {
        const wrapper = mountBlogIndex({
            categories: ['tech'],
            filters: { category: null },
        })
        const allPill = wrapper.find('nav.flex-wrap a[href="/blog"]')
        expect(allPill.classes()).toContain('bg-primary')
    })

    it('category pills link to filtered URL', () => {
        const wrapper = mountBlogIndex({ categories: ['tech'] })
        const pill = wrapper.find('a[href="/blog?category=tech"]')
        expect(pill.exists()).toBe(true)
    })

    it('renders category pills in the order received', () => {
        const wrapper = mountBlogIndex({ categories: ['beta', 'alpha'] })
        const pills = wrapper.findAll('nav.flex-wrap a')
        expect(pills[1].text()).toBe('beta')
        expect(pills[2].text()).toBe('alpha')
    })

    it('renders a search input', () => {
        const wrapper = mountBlogIndex()
        const input = wrapper.find('input[name="search"]')
        expect(input.exists()).toBe(true)
    })

    it('preserves category filter in search form via hidden input', () => {
        const wrapper = mountBlogIndex({ filters: { category: 'tech', search: null } })
        const hiddenInput = wrapper.find('input[name="category"][type="hidden"]')
        expect(hiddenInput.exists()).toBe(true)
        expect(hiddenInput.attributes('value')).toBe('tech')
    })

    it('omits hidden category input when no category is selected', () => {
        const wrapper = mountBlogIndex({ filters: { category: null, search: null } })
        const hiddenInput = wrapper.find('input[name="category"][type="hidden"]')
        expect(hiddenInput.exists()).toBe(false)
    })

    it('shows search term in the search input', () => {
        const wrapper = mountBlogIndex({ filters: { category: null, search: 'pizza' } })
        const input = wrapper.find<HTMLInputElement>('input[name="search"]')
        expect(input.element.value).toBe('pizza')
    })

    it('shows empty search result message when search returns no posts', () => {
        const wrapper = mountBlogIndex({
            posts: { data: [], links: [] },
            filters: { category: null, search: 'nothing' },
        })
        expect(wrapper.text()).toContain('No posts match your search')
    })

    it('shows generic empty state when no posts and no search term', () => {
        const wrapper = mountBlogIndex({
            posts: { data: [], links: [] },
            filters: { category: null, search: null },
        })
        expect(wrapper.text()).toContain('No articles yet')
        expect(wrapper.text()).not.toContain('No posts match your search')
    })
})
