import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import { useCountUp } from '@/composables/useCountUp'

function mountComposable(target: number | (() => number), duration = 1000, delay = 0) {
    let result: ReturnType<typeof useCountUp> | null = null
    const wrapper = mount(
        defineComponent({
            setup() {
                result = useCountUp(target, duration, delay)
                return () => h('div')
            },
        }),
    )
    return { wrapper, result: result! }
}

function mockMotion(reduced: boolean) {
    window.matchMedia = vi.fn().mockReturnValue({ matches: reduced }) as any
}

function mockRaf() {
    return vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
        cb(999999)
        return 1
    })
}

describe('useCountUp', () => {
    beforeEach(() => {
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
        vi.restoreAllMocks()
    })

    it('starts at 0', () => {
        const { result } = mountComposable(100)
        expect(result.value).toBe(0)
    })

    it.each([
        { reduced: true, rafCalls: 0, label: 'prefers-reduced-motion is set' },
        { reduced: false, rafCalls: 1, label: 'motion is allowed' },
    ])('jumps instantly to the target when $label', ({ reduced, rafCalls }) => {
        mockMotion(reduced)
        const raf = mockRaf()
        const { result } = mountComposable(100)
        expect(result.value).toBe(100)
        expect(raf).toHaveBeenCalledTimes(rafCalls)
    })

    it('tracks a reactive target function', async () => {
        mockMotion(true)
        const target = ref(100)
        const { result } = mountComposable(() => target.value)
        expect(result.value).toBe(100)
        target.value = 250
        await nextTick()
        expect(result.value).toBe(250)
    })

    it('delays the count-up by the given delay and starts at 0 before it fires', async () => {
        mockMotion(false)
        mockRaf()
        const { result } = mountComposable(100, 1000, 200)
        expect(result.value).toBe(0)
        await vi.advanceTimersByTimeAsync(199)
        expect(result.value).toBe(0)
        await vi.advanceTimersByTimeAsync(1)
        expect(result.value).toBe(100)
    })

    it.each([
        { next: 100, rafCalls: 1, label: 'does not re-animate when the target is unchanged after settling' },
        { next: 250, rafCalls: 2, label: 're-animates when the target changes to a new value' },
    ])('$label', async ({ next, rafCalls }) => {
        mockMotion(false)
        const raf = mockRaf()
        const target = ref(100)
        const { result } = mountComposable(() => target.value)
        await nextTick()
        expect(result.value).toBe(100)
        expect(raf).toHaveBeenCalledTimes(1)

        target.value = next
        await nextTick()
        expect(result.value).toBe(next)
        expect(raf).toHaveBeenCalledTimes(rafCalls)
    })
})