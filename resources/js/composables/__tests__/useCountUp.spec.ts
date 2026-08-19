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

    it('jumps instantly to the target when prefers-reduced-motion is set', () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as any
        const { result } = mountComposable(100)
        expect(result.value).toBe(100)
    })

    it('animates up to the target over time', () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as any
        const rafSpy = vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
            cb(1000)
            return 1
        })
        const { result } = mountComposable(100)
        expect(result.value).toBe(100)
        rafSpy.mockRestore()
    })

    it('tracks a reactive target function', async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as any
        const target = ref(100)
        const { result } = mountComposable(() => target.value)
        expect(result.value).toBe(100)
        target.value = 250
        await nextTick()
        expect(result.value).toBe(250)
    })

    it('delays the count-up by the given delay and starts at 0 before it fires', async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as any
        const rafSpy = vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
            cb(999999)
            return 1
        })
        const { result } = mountComposable(100, 1000, 200)
        expect(result.value).toBe(0)
        await vi.advanceTimersByTimeAsync(199)
        expect(result.value).toBe(0)
        await vi.advanceTimersByTimeAsync(1)
        expect(result.value).toBe(100)
        rafSpy.mockRestore()
    })

    it('does not re-animate when the target is unchanged after settling', async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as any
        const rafSpy = vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
            cb(999999)
            return 1
        })
        const target = ref(100)
        const { result } = mountComposable(() => target.value)
        await nextTick()
        expect(result.value).toBe(100)
        expect(rafSpy).toHaveBeenCalledTimes(1)

        await nextTick()
        target.value = 100
        await nextTick()
        expect(result.value).toBe(100)
        expect(rafSpy).toHaveBeenCalledTimes(1)
        rafSpy.mockRestore()
    })

    it('re-animates when the target changes to a new value', async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: false }) as any
        const rafSpy = vi.spyOn(window, 'requestAnimationFrame').mockImplementation((cb: FrameRequestCallback) => {
            cb(999999)
            return 1
        })
        const target = ref(100)
        const { result } = mountComposable(() => target.value)
        await nextTick()
        expect(result.value).toBe(100)
        expect(rafSpy).toHaveBeenCalledTimes(1)

        target.value = 250
        await nextTick()
        expect(result.value).toBe(250)
        expect(rafSpy).toHaveBeenCalledTimes(2)
        rafSpy.mockRestore()
    })
})