import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import ScrollReveal from '@/Components/ScrollReveal.vue'

const observers: MockIntersectionObserver[] = []

class MockIntersectionObserver {
    callback: IntersectionObserverCallback
    observe = vi.fn()
    unobserve = vi.fn()
    disconnect = vi.fn()
    root = null
    rootMargin = ''
    thresholds: ReadonlyArray<number>
    takeRecords = vi.fn()

    constructor(callback: IntersectionObserverCallback, options?: IntersectionObserverInit) {
        this.callback = callback
        this.thresholds = options?.threshold != null
            ? (Array.isArray(options.threshold) ? options.threshold : [options.threshold])
            : []
        observers.push(this)
    }

    trigger(isIntersecting: boolean) {
        const entry = { isIntersecting, intersectionRatio: isIntersecting ? 1 : 0 } as IntersectionObserverEntry
        this.callback([entry], this as unknown as IntersectionObserver)
    }
}

function mountReveal(props: Record<string, unknown> = {}) {
    return mount(ScrollReveal, {
        props,
        slots: { default: '<div class="content">Hello</div>' },
    })
}

describe('ScrollReveal', () => {
    beforeEach(() => {
        observers.length = 0
        vi.stubGlobal('IntersectionObserver', MockIntersectionObserver as unknown as typeof IntersectionObserver)
    })

    afterEach(() => {
        vi.unstubAllGlobals()
    })

    it('renders the wrapped slot content', () => {
        const wrapper = mountReveal()
        expect(wrapper.find('.content').text()).toBe('Hello')
    })

    it('starts hidden with the base class and no visible modifier', () => {
        const wrapper = mountReveal()
        const root = wrapper.find('.scroll-reveal')
        expect(root.exists()).toBe(true)
        expect(root.classes()).not.toContain('scroll-reveal--visible')
    })

    it('observes the root element on mount', () => {
        mountReveal()
        expect(observers).toHaveLength(1)
        expect(observers[0].observe).toHaveBeenCalled()
    })

    it('reveals and disconnects when the element intersects the viewport', async () => {
        const wrapper = mountReveal()
        observers[0].trigger(true)
        await nextTick()
        const root = wrapper.find('.scroll-reveal')
        expect(root.classes()).toContain('scroll-reveal--visible')
        expect(observers[0].disconnect).toHaveBeenCalled()
    })

    it('does not reveal while the element is out of view', async () => {
        const wrapper = mountReveal()
        observers[0].trigger(false)
        await nextTick()
        expect(wrapper.find('.scroll-reveal--visible').exists()).toBe(false)
    })

    it('reveals immediately when IntersectionObserver is unavailable', async () => {
        vi.stubGlobal('IntersectionObserver', undefined)
        const wrapper = mountReveal()
        await nextTick()
        expect(wrapper.find('.scroll-reveal--visible').exists()).toBe(true)
        expect(observers).toHaveLength(0)
    })

    it('reveals immediately and skips observing when reduced motion is preferred', async () => {
        vi.stubGlobal('matchMedia', vi.fn().mockImplementation((query: string) => ({
            matches: query.includes('prefers-reduced-motion'),
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })))
        const wrapper = mountReveal()
        await nextTick()
        expect(wrapper.find('.scroll-reveal--visible').exists()).toBe(true)
        expect(observers).toHaveLength(0)
    })

    it('still observes when reduced motion is not preferred', () => {
        vi.stubGlobal('matchMedia', vi.fn().mockImplementation((query: string) => ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        })))
        mountReveal()
        expect(observers).toHaveLength(1)
    })

    it('disconnects the observer on unmount', () => {
        const wrapper = mountReveal()
        wrapper.unmount()
        expect(observers[0].disconnect).toHaveBeenCalled()
    })

    it('applies a transition delay when revealed and a delay prop is set', async () => {
        const wrapper = mountReveal({ delay: 120 })
        observers[0].trigger(true)
        await nextTick()
        expect(wrapper.find('.scroll-reveal').attributes('style')).toContain('transition-delay: 120ms')
    })

    it('omits the transition delay style when no delay prop is set', async () => {
        const wrapper = mountReveal()
        observers[0].trigger(true)
        await nextTick()
        expect(wrapper.find('.scroll-reveal').attributes('style')).toBeUndefined()
    })
})
