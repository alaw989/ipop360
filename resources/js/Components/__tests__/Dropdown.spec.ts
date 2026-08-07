import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, VueWrapper } from '@vue/test-utils'
import Dropdown from '@/Components/Dropdown.vue'

const stubs = {
    Transition: {
        inheritAttrs: false,
        template: '<div><slot /></div>',
    },
}

const mountDropdown = (props = {}, slots = {}) => mount(Dropdown, {
    props,
    global: { stubs },
    attachTo: document.body,
    slots: {
        trigger: '<button class="trigger-btn">Menu</button>',
        content: '<a href="/settings">Settings</a>',
        ...slots,
    },
})

const clickTrigger = (wrapper: VueWrapper) =>
    wrapper.get('.relative > div').trigger('click')

describe('Dropdown', () => {
    beforeEach(() => {
        vi.restoreAllMocks()
    })

    it('renders closed by default', () => {
        const wrapper = mountDropdown()
        expect(wrapper.find('.fixed').isVisible()).toBe(false)
        expect(wrapper.find('a').isVisible()).toBe(false)
    })

    it('opens when trigger is clicked', async () => {
        const wrapper = mountDropdown()
        await clickTrigger(wrapper)
        expect(wrapper.find('.fixed').isVisible()).toBe(true)
        expect(wrapper.find('a').isVisible()).toBe(true)
    })

    it('closes when trigger is clicked again', async () => {
        const wrapper = mountDropdown()
        await clickTrigger(wrapper)
        expect(wrapper.find('.fixed').isVisible()).toBe(true)
        await clickTrigger(wrapper)
        expect(wrapper.find('.fixed').isVisible()).toBe(false)
    })

    it('closes when backdrop overlay is clicked', async () => {
        const wrapper = mountDropdown()
        await clickTrigger(wrapper)
        expect(wrapper.find('.fixed').isVisible()).toBe(true)
        await wrapper.find('.fixed').trigger('click')
        expect(wrapper.find('.fixed').isVisible()).toBe(false)
    })

    it('closes when content area is clicked', async () => {
        const wrapper = mountDropdown()
        await clickTrigger(wrapper)
        expect(wrapper.find('.fixed').isVisible()).toBe(true)
        await wrapper.find('.absolute').trigger('click')
        expect(wrapper.find('.fixed').isVisible()).toBe(false)
    })

    it('closes on Escape key when open', async () => {
        const wrapper = mountDropdown()
        await clickTrigger(wrapper)
        expect(wrapper.find('.fixed').isVisible()).toBe(true)
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('.fixed').isVisible()).toBe(false)
    })

    it('does not respond to Escape when closed', async () => {
        const wrapper = mountDropdown()
        expect(wrapper.find('.fixed').isVisible()).toBe(false)
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('.fixed').isVisible()).toBe(false)
    })

    it('does not respond to non-Escape keys', async () => {
        const wrapper = mountDropdown()
        await clickTrigger(wrapper)
        expect(wrapper.find('.fixed').isVisible()).toBe(true)
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' }))
        await wrapper.vm.$nextTick()
        expect(wrapper.find('.fixed').isVisible()).toBe(true)
    })

    it('applies right alignment classes by default', () => {
        const wrapper = mountDropdown()
        const menu = wrapper.find('.absolute')
        expect(menu.classes()).toContain('end-0')
        expect(menu.classes()).not.toContain('start-0')
    })

    it('applies left alignment classes when align is left', () => {
        const wrapper = mountDropdown({ align: 'left' })
        const menu = wrapper.find('.absolute')
        expect(menu.classes()).toContain('start-0')
        expect(menu.classes()).not.toContain('end-0')
    })

    it('applies width-48 class by default', () => {
        const wrapper = mountDropdown()
        expect(wrapper.find('.absolute').classes()).toContain('w-48')
    })

    it('applies default content classes', () => {
        const wrapper = mountDropdown()
        const inner = wrapper.find('.rounded-md.ring-1')
        expect(inner.classes()).toContain('py-1')
        expect(inner.classes()).toContain('bg-white')
    })

    it('applies custom contentClasses', () => {
        const wrapper = mountDropdown({ contentClasses: 'py-2 bg-gray-100' })
        const inner = wrapper.find('.rounded-md.ring-1')
        expect(inner.classes()).toContain('py-2')
        expect(inner.classes()).toContain('bg-gray-100')
    })

    it('renders trigger slot content', () => {
        const wrapper = mountDropdown({}, {
            trigger: '<span class="trigger-text">Open Menu</span>',
            content: '<span />',
        })
        expect(wrapper.text()).toContain('Open Menu')
    })

    it('renders content slot content when open', async () => {
        const wrapper = mountDropdown({}, {
            trigger: '<button />',
            content: '<span class="item">Profile</span><span class="item">Logout</span>',
        })
        await clickTrigger(wrapper)
        expect(wrapper.text()).toContain('Profile')
        expect(wrapper.text()).toContain('Logout')
    })

    it('removes keydown listener on unmount', () => {
        const addSpy = vi.spyOn(document, 'addEventListener')
        const removeSpy = vi.spyOn(document, 'removeEventListener')
        const wrapper = mountDropdown()
        expect(addSpy).toHaveBeenCalledWith('keydown', expect.any(Function))
        const registeredHandler = addSpy.mock.calls.find(
            (call) => call[0] === 'keydown',
        )![1]
        wrapper.unmount()
        expect(removeSpy).toHaveBeenCalledWith('keydown', registeredHandler)
    })
})
