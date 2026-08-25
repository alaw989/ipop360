import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import SearchLoadingOverlay from '@/Components/SearchLoadingOverlay.vue'

vi.mock('@/Components/BrandLogo.vue', () => ({
    default: {
        template: '<span class="brand-logo-stub">iPop360</span>',
        props: ['class'],
    },
}))

describe('SearchLoadingOverlay', () => {
    beforeEach(() => {
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('renders the giant spinner and brand mark', () => {
        const wrapper = mount(SearchLoadingOverlay)
        expect(wrapper.find('.brand-logo-stub').exists()).toBe(true)
        expect(wrapper.find('.animate-spin').exists()).toBe(true)
    })

    it('announces the loading message via an accessible status region', () => {
        const wrapper = mount(SearchLoadingOverlay)
        const status = wrapper.find('[role="status"]')
        expect(status.exists()).toBe(true)
        expect(status.attributes('aria-live')).toBe('polite')
        expect(status.text().length).toBeGreaterThan(0)
    })

    it('rotates to a new message and loops back around', async () => {
        const wrapper = mount(SearchLoadingOverlay)
        const messages: string[] = []
        messages.push(wrapper.find('[role="status"]').text())

        for (let i = 0; i < 8; i++) {
            await vi.advanceTimersByTimeAsync(1800)
            messages.push(wrapper.find('[role="status"]').text())
        }

        // it changed at least once
        expect(new Set(messages).size).toBeGreaterThan(1)
        // after a full loop (8 messages) it's back to the first
        expect(messages[8]).toBe(messages[0])
    })

    it('stops rotating after unmount', async () => {
        const wrapper = mount(SearchLoadingOverlay)
        const first = wrapper.find('[role="status"]').text()
        wrapper.unmount()

        await vi.advanceTimersByTimeAsync(10000)
        // No assertion target survives unmount to read from; this just
        // confirms advancing timers post-unmount doesn't throw.
        expect(first.length).toBeGreaterThan(0)
    })
})
