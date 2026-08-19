import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import { useCountUp } from '@/composables/useCountUp'

function mountComposable(target: number | (() => number), duration = 1000) {
    let result: ReturnType<typeof useCountUp> | null = null
    const wrapper = mount(
        defineComponent({
            setup() {
                result = useCountUp(target, duration)
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
})