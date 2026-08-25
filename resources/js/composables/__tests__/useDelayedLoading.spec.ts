import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { useDelayedLoading } from '@/composables/useDelayedLoading'

function mountComposable(showDelayMs = 200, minVisibleMs = 500) {
    let result: ReturnType<typeof useDelayedLoading> | null = null
    const wrapper = mount(
        defineComponent({
            setup() {
                result = useDelayedLoading(showDelayMs, minVisibleMs)
                return () => h('div')
            },
        }),
    )
    return { wrapper, result: result! }
}

describe('useDelayedLoading', () => {
    beforeEach(() => {
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    it('starts hidden', () => {
        const { result } = mountComposable()
        expect(result.isVisible.value).toBe(false)
    })

    it('never shows when the operation finishes before the show delay elapses', async () => {
        const { result } = mountComposable(200, 500)
        result.begin()
        await vi.advanceTimersByTimeAsync(100)
        result.end()
        await vi.advanceTimersByTimeAsync(500)
        expect(result.isVisible.value).toBe(false)
    })

    it('shows after the show delay elapses for a slow operation', async () => {
        const { result } = mountComposable(200, 500)
        result.begin()
        await vi.advanceTimersByTimeAsync(199)
        expect(result.isVisible.value).toBe(false)
        await vi.advanceTimersByTimeAsync(1)
        expect(result.isVisible.value).toBe(true)
    })

    it('keeps the indicator visible until the minimum-visible floor even if end() is called early', async () => {
        const { result } = mountComposable(200, 500)
        result.begin()
        await vi.advanceTimersByTimeAsync(200)
        expect(result.isVisible.value).toBe(true)

        result.end()
        await vi.advanceTimersByTimeAsync(499)
        expect(result.isVisible.value).toBe(true)
        await vi.advanceTimersByTimeAsync(1)
        expect(result.isVisible.value).toBe(false)
    })

    it('hides immediately when end() is called after the minimum-visible floor has already elapsed', async () => {
        const { result } = mountComposable(200, 500)
        result.begin()
        await vi.advanceTimersByTimeAsync(200)
        expect(result.isVisible.value).toBe(true)

        await vi.advanceTimersByTimeAsync(600)
        result.end()
        expect(result.isVisible.value).toBe(false)
    })

    it('clears pending timers on unmount', async () => {
        const { wrapper, result } = mountComposable(200, 500)
        result.begin()
        wrapper.unmount()
        await vi.advanceTimersByTimeAsync(1000)
        expect(result.isVisible.value).toBe(false)
    })
})
