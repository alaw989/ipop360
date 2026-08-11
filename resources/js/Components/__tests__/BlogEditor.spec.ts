import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import BlogEditor from '@/Components/BlogEditor.vue'

const mocks = vi.hoisted(() => {
    const mockActive = vi.fn()
    const mockSetContent = vi.fn()
    const mockGetHTML = vi.fn()
    const mockDestroy = vi.fn()

    const chainResult = {
        focus: vi.fn(function (this: any) { return this }),
        toggleBold: vi.fn(function (this: any) { return this }),
        toggleItalic: vi.fn(function (this: any) { return this }),
        toggleHeading: vi.fn(function (this: any) { return this }),
        toggleBulletList: vi.fn(function (this: any) { return this }),
        toggleOrderedList: vi.fn(function (this: any) { return this }),
        toggleBlockquote: vi.fn(function (this: any) { return this }),
        setLink: vi.fn(function (this: any) { return this }),
        unsetLink: vi.fn(function (this: any) { return this }),
        setImage: vi.fn(function (this: any) { return this }),
        run: vi.fn(),
    }

    function makeMockEditor(overrides: Record<string, any> = {}) {
        return {
            getHTML: mockGetHTML,
            chain: vi.fn(() => chainResult),
            isActive: mockActive,
            commands: { setContent: mockSetContent },
            destroy: mockDestroy,
            state: {
                selection: { empty: false },
                storedMarks: undefined as any[] | undefined,
            },
            ...overrides,
        }
    }

    const mockUseEditorFn = vi.fn((options: any) => {
        const { shallowRef } = require('vue')
        const editor = shallowRef(makeMockEditor())
        if (options?.onUpdate) {
            options.onUpdate({ editor: editor.value })
        }
        return editor
    })

    return { mockActive, mockSetContent, mockGetHTML, mockDestroy, chainResult, makeMockEditor, mockUseEditorFn }
})

vi.mock('@tiptap/vue-3', () => ({
    useEditor: mocks.mockUseEditorFn,
    EditorContent: {
        template: '<div class="editor-content"><slot /></div>',
        props: ['editor'],
    },
}))

vi.mock('lucide-vue', () => ({
    Bold: { template: '<svg data-icon="bold" />' },
    Heading2: { template: '<svg data-icon="heading2" />' },
    Heading3: { template: '<svg data-icon="heading3" />' },
    Italic: { template: '<svg data-icon="italic" />' },
    Link: { template: '<svg data-icon="link" />' },
    List: { template: '<svg data-icon="list" />' },
    ListOrdered: { template: '<svg data-icon="list-ordered" />' },
    Quote: { template: '<svg data-icon="quote" />' },
}))

vi.mock('@tiptap/starter-kit', () => ({ default: class StarterKit {} }))
vi.mock('@tiptap/extension-link', () => ({
    default: {
        configure: vi.fn(() => class LinkExtension {}),
    },
}))
vi.mock('@tiptap/extension-image', () => ({ default: class ImageExtension {} }))
vi.mock('@tiptap/extension-placeholder', () => ({
    default: {
        configure: vi.fn(() => class PlaceholderExtension {}),
    },
}))

function mountEditor(modelValue = '<p>Hello</p>', propsOverrides: Record<string, any> = {}) {
    return mount(BlogEditor, {
        props: { modelValue, ...propsOverrides },
    })
}

describe('BlogEditor', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        mocks.mockGetHTML.mockReturnValue('<p>Hello</p>')
        mocks.mockActive.mockReturnValue(false)
    })

    afterEach(() => {
        vi.restoreAllMocks()
    })

    describe('rendering', () => {
        it('renders the toolbar container', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('.rounded-lg.border').exists()).toBe(true)
        })

        it('renders bold button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Bold"]').exists()).toBe(true)
        })

        it('renders italic button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Italic"]').exists()).toBe(true)
        })

        it('renders heading 2 button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Heading 2"]').exists()).toBe(true)
        })

        it('renders heading 3 button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Heading 3"]').exists()).toBe(true)
        })

        it('renders bullet list button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Bullet list"]').exists()).toBe(true)
        })

        it('renders ordered list button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Numbered list"]').exists()).toBe(true)
        })

        it('renders blockquote button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Blockquote"]').exists()).toBe(true)
        })

        it('renders link button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Link"]').exists()).toBe(true)
        })

        it('renders image button', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('button[title="Image"]').exists()).toBe(true)
        })

        it('renders EditorContent when editor is initialized', () => {
            const wrapper = mountEditor()
            expect(wrapper.find('.editor-content').exists()).toBe(true)
        })

        it('passes modelValue content to useEditor', () => {
            mountEditor('<p>Initial content</p>')
            expect(mocks.mockUseEditorFn).toHaveBeenCalledWith(
                expect.objectContaining({ content: '<p>Initial content</p>' }),
            )
        })
    })

    describe('toolbar actions', () => {
        beforeEach(() => {
            mocks.chainResult.focus.mockClear()
            mocks.chainResult.toggleBold.mockClear()
            mocks.chainResult.toggleItalic.mockClear()
            mocks.chainResult.toggleHeading.mockClear()
            mocks.chainResult.toggleBulletList.mockClear()
            mocks.chainResult.toggleOrderedList.mockClear()
            mocks.chainResult.toggleBlockquote.mockClear()
            mocks.chainResult.run.mockClear()
        })

        it('bold button triggers toggleBold chain', async () => {
            const wrapper = mountEditor()
            await wrapper.find('button[title="Bold"]').trigger('mousedown')
            expect(mocks.chainResult.toggleBold).toHaveBeenCalled()
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })

        it('italic button triggers toggleItalic chain', async () => {
            const wrapper = mountEditor()
            await wrapper.find('button[title="Italic"]').trigger('mousedown')
            expect(mocks.chainResult.toggleItalic).toHaveBeenCalled()
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })

        it('heading 2 button triggers toggleHeading with level 2', async () => {
            const wrapper = mountEditor()
            await wrapper.find('button[title="Heading 2"]').trigger('mousedown')
            expect(mocks.chainResult.toggleHeading).toHaveBeenCalledWith({ level: 2 })
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })

        it('heading 3 button triggers toggleHeading with level 3', async () => {
            const wrapper = mountEditor()
            await wrapper.find('button[title="Heading 3"]').trigger('mousedown')
            expect(mocks.chainResult.toggleHeading).toHaveBeenCalledWith({ level: 3 })
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })

        it('bullet list button triggers toggleBulletList chain', async () => {
            const wrapper = mountEditor()
            await wrapper.find('button[title="Bullet list"]').trigger('mousedown')
            expect(mocks.chainResult.toggleBulletList).toHaveBeenCalled()
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })

        it('ordered list button triggers toggleOrderedList chain', async () => {
            const wrapper = mountEditor()
            await wrapper.find('button[title="Numbered list"]').trigger('mousedown')
            expect(mocks.chainResult.toggleOrderedList).toHaveBeenCalled()
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })

        it('blockquote button triggers toggleBlockquote chain', async () => {
            const wrapper = mountEditor()
            await wrapper.find('button[title="Blockquote"]').trigger('mousedown')
            expect(mocks.chainResult.toggleBlockquote).toHaveBeenCalled()
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })
    })

    describe('isActive styling', () => {
        it('applies active class when isActive returns true for bold', () => {
            mocks.mockActive.mockReturnValue(true)
            const wrapper = mountEditor()
            const btn = wrapper.find('button[title="Bold"]')
            expect(btn.classes()).toContain('bg-neutral-200')
        })

        it('does not apply active class when isActive returns false', () => {
            mocks.mockActive.mockReturnValue(false)
            const wrapper = mountEditor()
            const btn = wrapper.find('button[title="Bold"]')
            expect(btn.classes()).not.toContain('bg-neutral-200')
        })

        it('passes attrs to isActive for heading buttons', () => {
            mocks.mockActive.mockReturnValue(true)
            mountEditor()
            expect(mocks.mockActive).toHaveBeenCalledWith('heading', { level: 2 })
            expect(mocks.mockActive).toHaveBeenCalledWith('heading', { level: 3 })
        })
    })

    describe('link handling', () => {
        it('prompts for URL with empty selection and sets link', async () => {
            const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('https://example.com')
            mocks.mockUseEditorFn.mockImplementationOnce((options: any) => {
                const { shallowRef } = require('vue')
                const editor = shallowRef(mocks.makeMockEditor({
                    state: { selection: { empty: true }, storedMarks: [] },
                }))
                if (options?.onUpdate) {
                    options.onUpdate({ editor: editor.value })
                }
                return editor
            })
            const wrapper = mountEditor()
            mocks.chainResult.setLink.mockClear()
            mocks.chainResult.run.mockClear()

            await wrapper.find('button[title="Link"]').trigger('mousedown')
            expect(promptSpy).toHaveBeenCalledWith('Link URL', 'https://')
            expect(mocks.chainResult.setLink).toHaveBeenCalledWith({ href: 'https://example.com' })
            promptSpy.mockRestore()
        })

        it('does nothing when prompt is cancelled with empty selection', async () => {
            vi.spyOn(window, 'prompt').mockReturnValue(null)
            mocks.mockUseEditorFn.mockImplementationOnce((options: any) => {
                const { shallowRef } = require('vue')
                const editor = shallowRef(mocks.makeMockEditor({
                    state: { selection: { empty: true }, storedMarks: [] },
                }))
                if (options?.onUpdate) {
                    options.onUpdate({ editor: editor.value })
                }
                return editor
            })
            const wrapper = mountEditor()
            mocks.chainResult.run.mockClear()

            await wrapper.find('button[title="Link"]').trigger('mousedown')
            expect(mocks.chainResult.run).not.toHaveBeenCalled()
        })

        it('unlinks when selection has an existing link mark', async () => {
            mocks.mockUseEditorFn.mockImplementationOnce((options: any) => {
                const { shallowRef } = require('vue')
                const editor = shallowRef(mocks.makeMockEditor({
                    state: {
                        selection: { empty: false },
                        storedMarks: [{ type: { name: 'link' } }],
                    },
                }))
                if (options?.onUpdate) {
                    options.onUpdate({ editor: editor.value })
                }
                return editor
            })
            const wrapper = mountEditor()
            mocks.chainResult.unsetLink.mockClear()
            mocks.chainResult.run.mockClear()

            await wrapper.find('button[title="Link"]').trigger('mousedown')
            expect(mocks.chainResult.unsetLink).toHaveBeenCalled()
            expect(mocks.chainResult.run).toHaveBeenCalled()
        })

        it('prompts for URL with non-link selection and sets link', async () => {
            const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('https://example.com')
            mocks.mockUseEditorFn.mockImplementationOnce((options: any) => {
                const { shallowRef } = require('vue')
                const editor = shallowRef(mocks.makeMockEditor({
                    state: { selection: { empty: false }, storedMarks: [] },
                }))
                if (options?.onUpdate) {
                    options.onUpdate({ editor: editor.value })
                }
                return editor
            })
            const wrapper = mountEditor()
            mocks.chainResult.setLink.mockClear()
            mocks.chainResult.run.mockClear()

            await wrapper.find('button[title="Link"]').trigger('mousedown')
            expect(promptSpy).toHaveBeenCalled()
            expect(mocks.chainResult.setLink).toHaveBeenCalledWith({ href: 'https://example.com' })
            promptSpy.mockRestore()
        })

        it('does nothing when prompt is cancelled with non-link selection', async () => {
            vi.spyOn(window, 'prompt').mockReturnValue(null)
            mocks.mockUseEditorFn.mockImplementationOnce((options: any) => {
                const { shallowRef } = require('vue')
                const editor = shallowRef(mocks.makeMockEditor({
                    state: { selection: { empty: false }, storedMarks: [] },
                }))
                if (options?.onUpdate) {
                    options.onUpdate({ editor: editor.value })
                }
                return editor
            })
            const wrapper = mountEditor()
            mocks.chainResult.setLink.mockClear()
            mocks.chainResult.run.mockClear()

            await wrapper.find('button[title="Link"]').trigger('mousedown')
            expect(mocks.chainResult.setLink).not.toHaveBeenCalled()
        })
    })

    describe('image handling', () => {
        it('prompts for URL and inserts image', async () => {
            const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('https://example.com/img.jpg')
            const wrapper = mountEditor()
            mocks.chainResult.setImage.mockClear()
            mocks.chainResult.run.mockClear()

            await wrapper.find('button[title="Image"]').trigger('mousedown')
            expect(promptSpy).toHaveBeenCalledWith('Image URL')
            expect(mocks.chainResult.setImage).toHaveBeenCalledWith({ src: 'https://example.com/img.jpg' })
            expect(mocks.chainResult.run).toHaveBeenCalled()
            promptSpy.mockRestore()
        })

        it('does nothing when image prompt is cancelled', async () => {
            vi.spyOn(window, 'prompt').mockReturnValue(null)
            const wrapper = mountEditor()
            mocks.chainResult.setImage.mockClear()
            mocks.chainResult.run.mockClear()

            await wrapper.find('button[title="Image"]').trigger('mousedown')
            expect(mocks.chainResult.setImage).not.toHaveBeenCalled()
        })
    })

    describe('v-model sync', () => {
        it('emits update:modelValue when editor content changes', () => {
            const wrapper = mountEditor()
            expect(wrapper.emitted('update:modelValue')).toBeTruthy()
            expect(wrapper.emitted('update:modelValue')![0]).toEqual(['<p>Hello</p>'])
        })

        it('calls setContent when modelValue prop changes', async () => {
            const wrapper = mountEditor('<p>Old</p>')
            mocks.mockGetHTML.mockReturnValue('<p>Old</p>')
            mocks.mockSetContent.mockClear()

            await wrapper.setProps({ modelValue: '<p>New content</p>' })
            expect(mocks.mockSetContent).toHaveBeenCalledWith('<p>New content</p>')
        })

        it('does not call setContent when prop matches current content', async () => {
            const wrapper = mountEditor('<p>Same</p>')
            mocks.mockGetHTML.mockReturnValue('<p>Same</p>')
            mocks.mockSetContent.mockClear()

            await wrapper.setProps({ modelValue: '<p>Same</p>' })
            expect(mocks.mockSetContent).not.toHaveBeenCalled()
        })
    })

    describe('lifecycle', () => {
        it('destroys editor on unmount', () => {
            const wrapper = mountEditor()
            expect(mocks.mockDestroy).not.toHaveBeenCalled()
            wrapper.unmount()
            expect(mocks.mockDestroy).toHaveBeenCalled()
        })
    })
})
