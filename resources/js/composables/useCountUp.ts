import { ref, watch, onMounted, onUnmounted } from 'vue'

type CountUpTarget = number | (() => number)

function toGetter(target: CountUpTarget): () => number {
    return typeof target === 'function' ? target : () => target
}

function prefersReducedMotion(): boolean {
    if (typeof window === 'undefined') return false
    return window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
}

export function useCountUp(target: CountUpTarget, duration = 1000, delay = 0) {
    const value = ref(0)
    const getTarget = toGetter(target)
    let rafId: number | null = null
    let timeoutId: ReturnType<typeof setTimeout> | null = null
    let settledTarget: number | null = null

    function animateTo(to: number) {
        if (rafId !== null) cancelAnimationFrame(rafId)
        if (prefersReducedMotion() || to === value.value) {
            value.value = to
            settledTarget = to
            return
        }
        const from = value.value
        const start = performance.now()
        const tick = (now: number) => {
            const progress = Math.min((now - start) / duration, 1)
            const eased = 1 - Math.pow(1 - progress, 3)
            value.value = Math.round(from + (to - from) * eased)
            if (progress < 1) {
                rafId = requestAnimationFrame(tick)
            } else {
                value.value = to
                settledTarget = to
            }
        }
        rafId = requestAnimationFrame(tick)
    }

    function run() {
        const target = getTarget()
        if (target === settledTarget && value.value === settledTarget) {
            return
        }
        if (delay > 0 && !prefersReducedMotion()) {
            if (timeoutId !== null) clearTimeout(timeoutId)
            timeoutId = setTimeout(() => animateTo(target), delay)
        } else {
            animateTo(target)
        }
    }

    watch(getTarget, run)
    onMounted(run)
    onUnmounted(() => {
        if (rafId !== null) cancelAnimationFrame(rafId)
        if (timeoutId !== null) clearTimeout(timeoutId)
    })

    return value
}