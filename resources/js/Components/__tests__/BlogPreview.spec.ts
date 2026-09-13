import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import BlogPreview from '@/Components/BlogPreview.vue'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

function makePost(overrides: Record<string, any> = {}) {
    return {
        id: 1,
        title: 'Test Post Title',
        slug: 'test-post',
        excerpt: 'A test excerpt for the preview card.',
        featured_image: null,
        published_at: '2025-06-15T10:00:00.000000Z',
        author: { id: 99, name: 'Jane Doe' },
        is_featured: false,
        ...overrides,
    }
}

function mountComponent(posts: any[] = [makePost()]) {
    return mount(BlogPreview, {
        props: { posts },
    })
}

describe('BlogPreview', () => {
    it('renders nothing when posts array is empty', () => {
        const wrapper = mountComponent([])
        expect(wrapper.find('section').exists()).toBe(false)
    })

    it('is titled for what it is: the blog, not the featured restaurant', () => {
        const wrapper = mountComponent()
        expect(wrapper.get('h2').text()).toBe('From the blog')
        expect(wrapper.text()).not.toContain('Featured Restaurant')
    })

    it('links to all stories', () => {
        const wrapper = mountComponent()
        expect(wrapper.find('a[href="/blog"]').text()).toContain('All stories')
    })

    it('renders one card per post, each linking to its story', () => {
        const wrapper = mountComponent([makePost({ id: 1, slug: 'one' }), makePost({ id: 2, slug: 'two' })])
        expect(wrapper.findAll('[data-testid="blog-card"]')).toHaveLength(2)
        expect(wrapper.find('a[href="/blog/one"]').exists()).toBe(true)
        expect(wrapper.find('a[href="/blog/two"]').exists()).toBe(true)
    })

    it('keeps the words off the photo', () => {
        const wrapper = mountComponent([makePost({ featured_image: 'https://example.com/a.jpg' })])
        const card = wrapper.get('[data-testid="blog-card"]')
        expect(card.find('img').exists()).toBe(true)
        expect(card.find('.absolute.inset-0.bg-gradient-to-t').exists()).toBe(false)
    })

    it('shows a placeholder when a post has no image, or its image fails', async () => {
        expect(mountComponent([makePost({ featured_image: null })]).find('[data-testid="blog-placeholder"]').exists()).toBe(true)
        const wrapper = mountComponent([makePost({ featured_image: 'https://example.com/broken.jpg' })])
        await wrapper.get('img').trigger('error')
        expect(wrapper.find('[data-testid="blog-placeholder"]').exists()).toBe(true)
    })

    it('renders the title, excerpt, date and author', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Test Post Title')
        expect(wrapper.text()).toContain('A test excerpt for the preview card.')
        expect(wrapper.text()).toContain('Jun 15, 2025')
        expect(wrapper.text()).toContain('by Jane Doe')
    })

    it('leaves out the date and author when missing', () => {
        const wrapper = mountComponent([makePost({ published_at: null, author: null })])
        expect(wrapper.text()).not.toContain('by ')
    })

    it('shows the category as a quiet tag', () => {
        const wrapper = mountComponent([makePost({ category: 'Guides' })])
        expect(wrapper.get('[data-testid="blog-category"]').text()).toBe('Guides')
    })

    it('never shows a Featured badge or a "Featured" category tag', () => {
        const wrapper = mountComponent([makePost({ is_featured: true, category: 'Featured' })])
        expect(wrapper.find('[data-testid="blog-category"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Featured')
    })
})
