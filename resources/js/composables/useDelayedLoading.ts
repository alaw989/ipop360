import { ref, onUnmounted } from 'vue'

/**
 * Show-delay + minimum-visible-duration helper for a loading indicator tied
 * to an async operation of unknown length. Avoids two flicker cases: a fast
 * operation never shows the indicator at all, and once shown it stays up for
 * a minimum stretch even if the operation finishes a moment later.
 */
export function useDelayedLoading(showDelayMs = 200, minVisibleMs = 500) {
    const isVisible = ref(false)
    let showTimer: ReturnType<typeof setTimeout> | null = null
    let hideTimer: ReturnType<typeof setTimeout> | null = null
    let shownAt: number | null = null

    function begin() {
        if (hideTimer !== null) {
            clearTimeout(hideTimer)
            hideTimer = null
        }
        if (isVisible.value || showTimer !== null) return
        showTimer = setTimeout(() => {
            showTimer = null
            isVisible.value = true
            shownAt = Date.now()
        }, showDelayMs)
    }

    function end() {
        if (showTimer !== null) {
            clearTimeout(showTimer)
            showTimer = null
            return
        }
        if (!isVisible.value) return

        const elapsed = shownAt !== null ? Date.now() - shownAt : minVisibleMs
        const remaining = minVisibleMs - elapsed
        if (remaining > 0) {
            hideTimer = setTimeout(() => {
                hideTimer = null
                isVisible.value = false
                shownAt = null
            }, remaining)
        } else {
            isVisible.value = false
            shownAt = null
        }
    }

    onUnmounted(() => {
        if (showTimer !== null) clearTimeout(showTimer)
        if (hideTimer !== null) clearTimeout(hideTimer)
    })

    return { isVisible, begin, end }
}
