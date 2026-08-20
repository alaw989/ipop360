import { describe, it, expect } from 'vitest'
import { useImageFallback } from '@/composables/useImageFallback'

describe('useImageFallback', () => {
    it('starts with failed = false', () => {
        const { failed } = useImageFallback()
        expect(failed.value).toBe(false)
    })

    it('markFailed sets failed to true', () => {
        const { failed, markFailed } = useImageFallback()
        markFailed()
        expect(failed.value).toBe(true)
    })

    it('reset sets failed back to false', () => {
        const { failed, markFailed, reset } = useImageFallback()
        markFailed()
        reset()
        expect(failed.value).toBe(false)
    })
})
