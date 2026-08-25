import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, type Ref } from 'vue'

interface Overlay {
    isVisible: Ref<boolean>
    begin: () => void
    end: () => void
}

function mountConsumer(hook: () => Overlay) {
    let result: Overlay | null = null
    mount(
        defineComponent({
            setup() {
                result = hook()
                return () => h('div')
            },
        }),
    )
    return result!
}

describe('useSearchLoadingOverlay', () => {
    beforeEach(() => {
        vi.resetModules()
    })

    it('returns the same instance across multiple consumers', async () => {
        const { useSearchLoadingOverlay } = await import('@/composables/useSearchLoadingOverlay')
        const first = mountConsumer(useSearchLoadingOverlay)
        const second = mountConsumer(useSearchLoadingOverlay)
        expect(first).toBe(second)
    })

    it('a state change made through one consumer is visible through another', async () => {
        vi.useFakeTimers()
        const { useSearchLoadingOverlay } = await import('@/composables/useSearchLoadingOverlay')
        const a = mountConsumer(useSearchLoadingOverlay)
        const b = mountConsumer(useSearchLoadingOverlay)

        expect(b.isVisible.value).toBe(false)
        a.begin()
        await vi.advanceTimersByTimeAsync(200)
        expect(b.isVisible.value).toBe(true)

        vi.useRealTimers()
    })
})
