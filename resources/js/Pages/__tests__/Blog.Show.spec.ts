import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BlogShow from '@/Pages/Blog/Show.vue'
import { useSeo } from '@/composables/useSeo'

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
    generateArticleJsonLd: vi.fn(() => ({})),
}))

vi.mock('@/composables/useBaseUrl', () => ({
    useBaseUrl: vi.fn(() => 'http://localhost'),
}))

const stubs = {
    AppLayout: { template: '<div><slot /></div>' },
    SeoMeta: { template: '<div />' },
    JsonLd: { template: '<div />' },
}

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    body: string
    featured_image: string | null
    published_at: string | null
    author: { name: string } | null
}

function makePost(overrides: Partial<BlogPost> = {}): BlogPost {
    return {
        id: 1,
        title: 'Test Blog Post',
        slug: 'test-blog-post',
        excerpt: 'This is a test excerpt.',
        body: '<p>Paragraph one.</p><p>Paragraph two.</p>',
        featured_image: 'https://example.com/img.jpg',
        published_at: '2025-06-15T12:00:00.000000Z',
        author: { name: 'Jane Doe' },
        ...overrides,
    }
}

function mountBlogShow(postOverrides: Partial<BlogPost> = {}) {
    return mount(BlogShow, {
        props: {
            post: makePost(postOverrides),
        },
        global: { stubs },
    })
}

describe('Blog Show page', () => {
    it('renders the post title as a heading', () => {
        const wrapper = mountBlogShow({ title: 'My Custom Title' })
        expect(wrapper.text()).toContain('My Custom Title')
    })

    it('renders a back to blog link', () => {
        const wrapper = mountBlogShow()
        const link = wrapper.find('a[href="/blog"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('Back to blog')
    })

    it('renders the author name when present', () => {
        const wrapper = mountBlogShow({ author: { name: 'John Smith' } })
        expect(wrapper.text()).toContain('John Smith')
    })

    it('does not render author area when author is null', () => {
        const wrapper = mountBlogShow({ author: null })
        expect(wrapper.text()).not.toContain('Jane Doe')
    })

    it('renders the formatted published date', () => {
        const wrapper = mountBlogShow({ published_at: '2025-03-10T12:00:00.000000Z' })
        expect(wrapper.text()).toContain('March 10, 2025')
    })

    it('does not render date label when published_at is null', () => {
        const wrapper = mountBlogShow({ published_at: null })
        expect(wrapper.text()).not.toContain('June')
    })

    it('renders the featured image when present', () => {
        const wrapper = mountBlogShow({ featured_image: 'https://example.com/photo.jpg' })
        const img = wrapper.find('img')
        expect(img.exists()).toBe(true)
        expect(img.attributes('src')).toBe('https://example.com/photo.jpg')
    })

    it('does not render an image when featured_image is null', () => {
        const wrapper = mountBlogShow({ featured_image: null })
        expect(wrapper.find('img').exists()).toBe(false)
    })

    it('renders post body as inner HTML', () => {
        const wrapper = mountBlogShow({ body: '<p>Hello <strong>world</strong></p>' })
        expect(wrapper.html()).toContain('<p>Hello <strong>world</strong></p>')
    })

    it('renders SeoMeta component', () => {
        const wrapper = mountBlogShow()
        expect(wrapper.findComponent(stubs.SeoMeta).exists()).toBe(true)
    })

    it('renders JsonLd component', () => {
        const wrapper = mountBlogShow()
        expect(wrapper.findComponent(stubs.JsonLd).exists()).toBe(true)
    })

    it('renders post excerpt for SEO description', () => {
        mountBlogShow({ excerpt: 'Tasty food tips!' })
        expect(useSeo).toHaveBeenCalledWith(
            expect.objectContaining({ description: 'Tasty food tips!' }),
        )
    })

    it('includes article type in SEO data', () => {
        mountBlogShow()
        expect(useSeo).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'article' }),
        )
    })
})
