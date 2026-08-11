import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import Modal from '@/Components/Modal.vue'

beforeEach(() => {
    vi.restoreAllMocks()
})

afterEach(() => {
    vi.restoreAllMocks()
})

const stubs = {
    Transition: {
        inheritAttrs: false,
        template: '<div><slot /></div>',
    },
}

function polyfillDialog() {
    HTMLDialogElement.prototype.showModal = vi.fn(function (this: HTMLDialogElement) {
        this.setAttribute('open', '')
    })
    HTMLDialogElement.prototype.close = vi.fn(function (this: HTMLDialogElement) {
        this.removeAttribute('open')
    })
}

const mountModal = (props = {}, slots = {}) => {
    polyfillDialog()
    return mount(Modal, {
        props,
        global: { stubs },
        attachTo: document.body,
        slots,
    })
}

describe('Modal', () => {
    describe('rendering', () => {
        it('renders content when show is true', () => {
            const wrapper = mountModal({ show: true }, { default: '<p class="modal-content">Hello</p>' })
            expect(wrapper.text()).toContain('Hello')
        })

        it('does not render content when show is false', () => {
            const wrapper = mountModal({ show: false }, { default: '<p class="modal-content">Hello</p>' })
            expect(wrapper.find('.modal-content').exists()).toBe(false)
        })

        it('renders the dialog element', () => {
            const wrapper = mountModal()
            expect(wrapper.find('dialog').exists()).toBe(true)
        })
    })

    describe('props', () => {
        it('defaults show to false', () => {
            const wrapper = mountModal()
            expect(wrapper.props('show')).toBe(false)
        })

        it('defaults maxWidth to 2xl', () => {
            const wrapper = mountModal()
            expect(wrapper.props('maxWidth')).toBe('2xl')
        })

        it('defaults closeable to true', () => {
            const wrapper = mountModal()
            expect(wrapper.props('closeable')).toBe(true)
        })
    })

    describe('maxWidthClass', () => {
        it('applies sm max-w class', () => {
            const wrapper = mountModal({ show: true, maxWidth: 'sm' })
            expect(wrapper.find('.mb-6').classes()).toContain('sm:max-w-sm')
        })

        it('applies md max-w class', () => {
            const wrapper = mountModal({ show: true, maxWidth: 'md' })
            expect(wrapper.find('.mb-6').classes()).toContain('sm:max-w-md')
        })

        it('applies lg max-w class', () => {
            const wrapper = mountModal({ show: true, maxWidth: 'lg' })
            expect(wrapper.find('.mb-6').classes()).toContain('sm:max-w-lg')
        })

        it('applies xl max-w class', () => {
            const wrapper = mountModal({ show: true, maxWidth: 'xl' })
            expect(wrapper.find('.mb-6').classes()).toContain('sm:max-w-xl')
        })

        it('applies 2xl max-w class', () => {
            const wrapper = mountModal({ show: true, maxWidth: '2xl' })
            expect(wrapper.find('.mb-6').classes()).toContain('sm:max-w-2xl')
        })
    })

    describe('backdrop click', () => {
        it('emits close when backdrop is clicked and closeable is true', () => {
            const wrapper = mountModal({ show: true, closeable: true })
            const backdrop = wrapper.findAll('.fixed.inset-0')[1]
            backdrop.trigger('click')
            expect(wrapper.emitted('close')).toBeTruthy()
        })

        it('does not emit close when backdrop is clicked and closeable is false', () => {
            const wrapper = mountModal({ show: true, closeable: false })
            const backdrop = wrapper.findAll('.fixed.inset-0')[1]
            backdrop.trigger('click')
            expect(wrapper.emitted('close')).toBeFalsy()
        })
    })

    describe('close on Escape', () => {
        it('emits close when Escape is pressed and show is true', async () => {
            const wrapper = mountModal({ show: true })
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
            await wrapper.vm.$nextTick()
            expect(wrapper.emitted('close')).toBeTruthy()
        })

        it('does not emit close on Escape when show is false', async () => {
            const wrapper = mountModal({ show: false })
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
            await wrapper.vm.$nextTick()
            expect(wrapper.emitted('close')).toBeFalsy()
        })

        it('does not emit close on non-Escape keys', async () => {
            const wrapper = mountModal({ show: true })
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
            await wrapper.vm.$nextTick()
            expect(wrapper.emitted('close')).toBeFalsy()
        })
    })

    describe('body overflow', () => {
        it('sets body overflow to hidden when show transitions to true', async () => {
            const wrapper = mountModal({ show: false })
            await wrapper.setProps({ show: true })
            expect(document.body.style.overflow).toBe('hidden')
        })

        it('clears body overflow when show transitions to false', async () => {
            const wrapper = mountModal({ show: true })
            await wrapper.setProps({ show: false })
            expect(document.body.style.overflow).toBe('')
        })
    })

    describe('lifecycle', () => {
        it('registers keydown listener on mount', () => {
            const addSpy = vi.spyOn(document, 'addEventListener')
            mountModal()
            expect(addSpy).toHaveBeenCalledWith('keydown', expect.any(Function))
        })

        it('removes keydown listener on unmount', () => {
            const addSpy = vi.spyOn(document, 'addEventListener')
            const removeSpy = vi.spyOn(document, 'removeEventListener')
            const wrapper = mountModal()
            const registeredHandler = addSpy.mock.calls.find(
                (call) => call[0] === 'keydown',
            )![1]
            wrapper.unmount()
            expect(removeSpy).toHaveBeenCalledWith('keydown', registeredHandler)
        })

        it('clears body overflow on unmount', async () => {
            const wrapper = mountModal({ show: false })
            await wrapper.setProps({ show: true })
            expect(document.body.style.overflow).toBe('hidden')
            wrapper.unmount()
            expect(document.body.style.overflow).toBe('')
        })
    })

    describe('dialog methods', () => {
        it('calls showModal when show becomes true', async () => {
            polyfillDialog()
            const wrapper = mountModal({ show: false })
            const showModalSpy = vi.spyOn(HTMLDialogElement.prototype, 'showModal')
            await wrapper.setProps({ show: true })
            expect(showModalSpy).toHaveBeenCalled()
        })

        it('calls close after timeout when show becomes false', async () => {
            vi.useFakeTimers()
            polyfillDialog()
            const wrapper = mountModal({ show: true })
            const closeSpy = vi.spyOn(HTMLDialogElement.prototype, 'close')
            await wrapper.setProps({ show: false })
            vi.advanceTimersByTime(200)
            expect(closeSpy).toHaveBeenCalled()
            vi.useRealTimers()
        })
    })

    describe('closeable', () => {
        it('does not emit close on Escape when closeable is false', async () => {
            const wrapper = mountModal({ show: true, closeable: false })
            document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
            await wrapper.vm.$nextTick()
            expect(wrapper.emitted('close')).toBeFalsy()
        })
    })
})
