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

    it('renders the section header with title and subtitle', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Featured Restaurant')
        expect(wrapper.text()).toContain('Guides, trends, and dining insights')
    })

    it('renders the section as a plain (background) full-width band', () => {
        const wrapper = mountComponent()
        const section = wrapper.find('section')
        expect(section.classes()).toContain('bg-background')
        expect(section.classes()).toContain('w-full')
    })

    it('renders a "View all" link pointing to /blog', () => {
        const wrapper = mountComponent()
        const link = wrapper.find('a[href="/blog"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('View all')
    })

    it('renders the hero post as the first item', () => {
        const posts = [
            makePost({ id: 1, title: 'Hero Post' }),
            makePost({ id: 2, title: 'Grid Post' }),
        ]
        const wrapper = mountComponent(posts)
        const text = wrapper.text()
        expect(text.indexOf('Hero Post')).toBeLessThan(text.indexOf('Grid Post'))
    })

    it('renders "Read more" on the hero post', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Read more')
    })

    it('does not render the grid section when only one post', () => {
        const wrapper = mountComponent([makePost()])
        const gridCards = wrapper.findAll('a[href^="/blog/"]')
        expect(gridCards).toHaveLength(1)
    })

    it('renders grid posts alongside the hero', () => {
        const posts = [
            makePost({ id: 1, title: 'Hero', slug: 'hero' }),
            makePost({ id: 2, title: 'Second', slug: 'second' }),
            makePost({ id: 3, title: 'Third', slug: 'third' }),
        ]
        const wrapper = mountComponent(posts)
        expect(wrapper.text()).toContain('Second')
        expect(wrapper.text()).toContain('Third')
        expect(wrapper.html()).toContain('href="/blog/second"')
        expect(wrapper.html()).toContain('href="/blog/third"')
    })

    it('shows the featured image on the hero post when present', () => {
        const wrapper = mountComponent([makePost({ featured_image: '/img/test.jpg' })])
        const img = wrapper.find('img')
        expect(img.exists()).toBe(true)
        expect(img.attributes('src')).toBe('/img/test.jpg')
        expect(img.attributes('alt')).toBe('Test Post Title')
    })

    it('constrains the hero card to the featured image native width', () => {
        const wrapper = mountComponent([makePost({ featured_image: '/img/test.jpg' })])
        expect(wrapper.html()).toContain('max-w-[1067px]')
        expect(wrapper.html()).toContain('mx-auto')
    })

    it('shows the placeholder when the hero image fails to load', async () => {
        const wrapper = mountComponent([makePost({ featured_image: '/img/broken.jpg' })])
        expect(wrapper.find('img').exists()).toBe(true)
        await wrapper.find('img').trigger('error')
        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.text()).toContain('No image')
    })

    it('shows the placeholder when a grid image fails to load', async () => {
        const posts = [
            makePost({ id: 1, title: 'Hero', featured_image: '/img/hero.jpg' }),
            makePost({ id: 2, title: 'Grid', featured_image: '/img/grid-broken.jpg' }),
        ]
        const wrapper = mountComponent(posts)
        expect(wrapper.findAll('img')).toHaveLength(2)
        await wrapper.findAll('img')[1].trigger('error')
        expect(wrapper.findAll('img')).toHaveLength(1)
        expect(wrapper.text()).toContain('No image')
    })

    it('renders a gradient placeholder on the hero when no image', () => {
        const wrapper = mountComponent([makePost({ featured_image: null })])
        expect(wrapper.find('img').exists()).toBe(false)
        expect(wrapper.text()).toContain('No image')
    })

    it('does not render an image in grid posts when featured_image is null', () => {
        const posts = [
            makePost({ id: 1, featured_image: '/img/hero.jpg' }),
            makePost({ id: 2, featured_image: null, title: 'No Image Post' }),
        ]
        const wrapper = mountComponent(posts)
        const imgs = wrapper.findAll('img')
        expect(imgs).toHaveLength(1)
        expect(imgs[0].attributes('src')).toBe('/img/hero.jpg')
    })

    it('renders a decorative placeholder in grid posts when no image', () => {
        const posts = [
            makePost({ id: 1, featured_image: '/img/hero.jpg' }),
            makePost({ id: 2, featured_image: null, title: 'Placeholder Post' }),
        ]
        const wrapper = mountComponent(posts)
        const gridPlaceholders = wrapper.findAll('.group .aspect-video .flex .opacity-20')
        expect(gridPlaceholders).toHaveLength(1)
    })

    it('renders the post title', () => {
        const wrapper = mountComponent([makePost({ title: 'My Blog Article' })])
        expect(wrapper.text()).toContain('My Blog Article')
    })

    it('renders the post excerpt', () => {
        const wrapper = mountComponent([makePost({ excerpt: 'Short preview text.' })])
        expect(wrapper.text()).toContain('Short preview text.')
    })

    it('formats and displays the published date', () => {
        const wrapper = mountComponent([makePost({ published_at: '2025-03-01T12:00:00.000000Z' })])
        expect(wrapper.text()).toContain('Mar 1, 2025')
    })

    it('renders nothing for date when published_at is null', () => {
        const wrapper = mountComponent([makePost({ published_at: null })])
        expect(wrapper.text()).not.toContain(', 2025')
        const calendarIcons = wrapper.findAll('svg')
        expect(calendarIcons.length).toBeGreaterThanOrEqual(1)
    })

    it('renders the author name on the hero post', () => {
        const wrapper = mountComponent([makePost({ author: { id: 1, name: 'Alice' } })])
        expect(wrapper.text()).toContain('Alice')
    })

    it('renders the author name on grid posts', () => {
        const posts = [
            makePost({ id: 1, title: 'Hero' }),
            makePost({ id: 2, title: 'Grid', author: { id: 2, name: 'Bob' } }),
        ]
        const wrapper = mountComponent(posts)
        expect(wrapper.text()).toContain('Bob')
    })

    it('does not render author when author is null', () => {
        const wrapper = mountComponent([makePost({ author: null })])
        expect(wrapper.text()).not.toContain('Jane Doe')
        expect(wrapper.text()).not.toContain('Alice')
    })

    it('does not render author when author is undefined', () => {
        const wrapper = mountComponent([makePost({ author: undefined })])
        expect(wrapper.text()).not.toContain('Jane Doe')
    })

    it('renders the category badge on the hero post when category is set', () => {
        const wrapper = mountComponent([makePost({ category: 'Reviews' })])
        expect(wrapper.text()).toContain('Reviews')
    })

    it('renders the category badge on grid posts when category is set', () => {
        const posts = [
            makePost({ id: 1, title: 'Hero' }),
            makePost({ id: 2, title: 'Grid', category: 'News' }),
        ]
        const wrapper = mountComponent(posts)
        expect(wrapper.text()).toContain('News')
    })

    it('does not render a category badge when category is null', () => {
        const wrapper = mountComponent([makePost({ category: null })])
        expect(wrapper.text()).not.toContain('Reviews')
        expect(wrapper.text()).not.toContain('News')
    })

    it('does not render a category badge when category is undefined', () => {
        const wrapper = mountComponent([makePost({ category: undefined })])
        expect(wrapper.text()).not.toContain('Reviews')
    })

    it('links the hero card to /blog/:slug', () => {
        const wrapper = mountComponent([makePost({ slug: 'delicious-tacos' })])
        const link = wrapper.find('a[href="/blog/delicious-tacos"]')
        expect(link.exists()).toBe(true)
    })

    it('renders a Featured badge on the hero post when is_featured is true', () => {
        const wrapper = mountComponent([makePost({ is_featured: true })])
        expect(wrapper.text()).toContain('Featured')
    })

    it('does not render a Featured badge on the hero post when is_featured is false', () => {
        const wrapper = mountComponent([makePost({ is_featured: false })])
        expect(wrapper.findAll('span').some(el => el.text() === 'Featured')).toBe(false)
    })

    it('renders a Featured badge on grid posts when is_featured is true', () => {
        const posts = [
            makePost({ id: 1, title: 'Hero' }),
            makePost({ id: 2, title: 'Grid', is_featured: true }),
        ]
        const wrapper = mountComponent(posts)
        expect(wrapper.text()).toContain('Featured')
    })

    it('does not render a Featured badge on grid posts when is_featured is false', () => {
        const posts = [
            makePost({ id: 1, title: 'Hero', is_featured: false }),
            makePost({ id: 2, title: 'Grid', is_featured: false }),
        ]
        const wrapper = mountComponent(posts)
        expect(wrapper.findAll('span').some(el => el.text() === 'Featured')).toBe(false)
    })
})
