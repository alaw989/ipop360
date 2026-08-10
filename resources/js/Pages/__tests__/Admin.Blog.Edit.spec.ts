import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import type { Reactive } from 'vue'
import AdminBlogEdit from '@/Pages/Admin/Blog/Edit.vue'

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    body: string
    featured_image: string | null
    status: string
    published_at: string | null
    author: { name: string } | null
}

function makePost(overrides: Partial<BlogPost> = {}): BlogPost {
    return {
        id: 1,
        title: 'Test Blog Post',
        slug: 'test-blog-post',
        excerpt: 'Test excerpt',
        body: '<p>Test body content</p>',
        featured_image: null,
        status: 'published',
        published_at: '2025-06-15T12:00:00.000000Z',
        author: { name: 'Admin User' },
        ...overrides,
    }
}

const mockRoute = vi.fn((name: string, params?: any) => {
    const routes: Record<string, string> = {
        'admin.blog.index': '/admin/blog',
        'admin.blog.store': '/admin/blog',
    }
    if (routes[name]) return routes[name]
    if (name === 'admin.blog.update') return `/admin/blog/${params}`
    return '#'
})

;(globalThis as any).route = mockRoute

let presetErrors: Record<string, string> = {}
let presetProcessing = false
let mountedForm: Record<string, any> | null = null

const { useFormMock } = vi.hoisted(() => ({
    useFormMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    const { reactive } = await import('vue')
    return {
        ...actual as any,
        Head: { template: '<div />' },
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
        useForm: useFormMock.mockImplementation((data: any) => {
            const form = reactive({
                ...data,
                errors: presetErrors,
                processing: presetProcessing,
                put: vi.fn(),
                post: vi.fn(),
            }) as Record<string, any>
            mountedForm = form
            return form
        }),
    }
})

const stubs = {
    AuthenticatedLayout: { template: '<div><slot name="header" /><slot /></div>' },
    Button: { template: '<button :type="type" :disabled="disabled"><slot /></button>', props: ['type', 'disabled', 'variant', 'size', 'asChild'] },
    Input: {
        template: '<input :id="id" :type="type" :placeholder="placeholder" :required="required" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)" />',
        props: ['id', 'type', 'placeholder', 'required', 'modelValue'],
        emits: ['update:modelValue'],
    },
    BlogEditor: { template: '<div />', props: ['modelValue'], emits: ['update:modelValue'] },
}

function mountComponent(propsOverrides: Record<string, any> = {}) {
    return mount(AdminBlogEdit, {
        props: {
            post: makePost(),
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

describe('Admin Blog Edit page', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        presetErrors = {}
        presetProcessing = false
        mountedForm = null
    })

    it('renders "Edit Blog Post" heading when post is provided', () => {
        const wrapper = mountComponent({ post: makePost() })
        expect(wrapper.text()).toContain('Edit Blog Post')
    })

    it('renders "New Blog Post" heading when post is null', () => {
        const wrapper = mountComponent({ post: null })
        expect(wrapper.text()).toContain('New Blog Post')
    })

    it('pre-fills title from post data in edit mode', () => {
        const wrapper = mountComponent({ post: makePost({ title: 'Existing Title' }) })
        const input = wrapper.find('#title')
        expect(input.attributes('value')).toBe('Existing Title')
    })

    it('pre-fills excerpt from post data in edit mode', () => {
        const wrapper = mountComponent({ post: makePost({ excerpt: 'Existing excerpt' }) })
        const textarea = wrapper.find('#excerpt')
        expect(textarea.element.value).toBe('Existing excerpt')
    })

    it('pre-fills body from post data in edit mode', () => {
        const wrapper = mountComponent({ post: makePost({ body: '<p>Existing body</p>' }) })
        const editor = wrapper.findComponent(stubs.BlogEditor)
        expect(editor.props('modelValue')).toBe('<p>Existing body</p>')
    })

    it('pre-fills featured_image from post data in edit mode', () => {
        const wrapper = mountComponent({ post: makePost({ featured_image: 'https://example.com/img.jpg' }) })
        const input = wrapper.find('#featured_image')
        expect(input.attributes('value')).toBe('https://example.com/img.jpg')
    })

    it('has empty title field when creating a new post', () => {
        const wrapper = mountComponent({ post: null })
        const input = wrapper.find('#title')
        expect(input.attributes('value')).toBe('')
    })

    it('has empty excerpt field when creating a new post', () => {
        const wrapper = mountComponent({ post: null })
        const textarea = wrapper.find('#excerpt')
        expect(textarea.element.value).toBe('')
    })

    it('has empty featured_image field when creating a new post', () => {
        const wrapper = mountComponent({ post: null })
        const input = wrapper.find('#featured_image')
        expect(input.attributes('value')).toBe('')
    })

    it('renders a Cancel link pointing to admin blog index', () => {
        const wrapper = mountComponent()
        const cancelLink = wrapper.find('a[href="/admin/blog"]')
        expect(cancelLink.exists()).toBe(true)
        expect(cancelLink.text()).toBe('Cancel')
    })

    it('submit button says "Update Post" in edit mode', () => {
        const wrapper = mountComponent({ post: makePost() })
        const btn = wrapper.find('button[type="submit"]')
        expect(btn.text()).toBe('Update Post')
    })

    it('submit button says "Create Post" in create mode', () => {
        const wrapper = mountComponent({ post: null })
        const btn = wrapper.find('button[type="submit"]')
        expect(btn.text()).toBe('Create Post')
    })

    it('displays title validation error', () => {
        presetErrors = { title: 'The title field is required.' }
        const wrapper = mountComponent({ post: null })
        expect(wrapper.text()).toContain('The title field is required.')
    })

    it('displays excerpt validation error', () => {
        presetErrors = { excerpt: 'The excerpt field is required.' }
        const wrapper = mountComponent({ post: null })
        expect(wrapper.text()).toContain('The excerpt field is required.')
    })

    it('displays body validation error inline', () => {
        presetErrors = { body: 'The body field is required.' }
        const wrapper = mountComponent({ post: null })
        expect(wrapper.text()).toContain('The body field is required.')
    })

    it('shows body error box when body error exists', () => {
        presetErrors = { body: 'Content cannot be empty.' }
        const wrapper = mountComponent({ post: null })
        const errorBox = wrapper.find('.bg-red-50')
        expect(errorBox.exists()).toBe(true)
        expect(errorBox.text()).toBe('Content cannot be empty.')
    })

    it('displays featured_image validation error', () => {
        presetErrors = { featured_image: 'Must be a valid URL.' }
        const wrapper = mountComponent({ post: null })
        expect(wrapper.text()).toContain('Must be a valid URL.')
    })

    it('shows "Saving…" when form is processing', () => {
        presetProcessing = true
        const wrapper = mountComponent({ post: makePost() })
        const btn = wrapper.find('button[type="submit"]')
        expect(btn.text()).toBe('Saving…')
    })

    it('disables submit button when form is processing', () => {
        presetProcessing = true
        const wrapper = mountComponent()
        const btn = wrapper.find('button[type="submit"]')
        expect(btn.attributes('disabled')).toBeDefined()
    })

    it('calls form.put with correct route on edit submit', async () => {
        const wrapper = mountComponent({ post: makePost({ id: 42 }) })
        await wrapper.find('form').trigger('submit.prevent')
        expect(mountedForm!.put).toHaveBeenCalledWith('/admin/blog/42')
    })

    it('calls form.post with correct route on create submit', async () => {
        const wrapper = mountComponent({ post: null })
        await wrapper.find('form').trigger('submit.prevent')
        expect(mountedForm!.post).toHaveBeenCalledWith('/admin/blog')
    })

    it('status select defaults to draft in create mode', () => {
        const wrapper = mountComponent({ post: null })
        const select = wrapper.find('select')
        expect((select.element as HTMLSelectElement).value).toBe('draft')
    })

    it('status select reflects post status in edit mode', () => {
        const wrapper = mountComponent({ post: makePost({ status: 'published' }) })
        const select = wrapper.find('select')
        expect((select.element as HTMLSelectElement).value).toBe('published')
    })

    it('has required attribute on title input', () => {
        const wrapper = mountComponent()
        const input = wrapper.find('#title')
        expect(input.attributes('required')).toBeDefined()
    })

    it('has required attribute on excerpt textarea', () => {
        const wrapper = mountComponent()
        const textarea = wrapper.find('#excerpt')
        expect(textarea.attributes('required')).toBeDefined()
    })
})
