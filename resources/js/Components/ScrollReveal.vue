<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'

const props = withDefaults(
    defineProps<{
        /** Extra delay (ms) before the reveal transition starts (for staggering). */
        delay?: number
        /** Fraction of the element that must be visible to trigger (0–1). */
        threshold?: number
    }>(),
    { delay: 0, threshold: 0.15 },
)

const root = ref<HTMLElement | null>(null)
const revealed = ref(false)

let observer: IntersectionObserver | null = null

function reveal() {
    if (revealed.value) return
    revealed.value = true
    observer?.disconnect()
    observer = null
}

onMounted(() => {
    // No observer available (legacy browser / test env): show the content.
    if (!root.value || typeof window.IntersectionObserver === 'undefined') {
        revealed.value = true
        return
    }

    // Reduced-motion: transitions.css already forces .scroll-reveal visible
    // with no transition, so skip creating an observer entirely and mark
    // revealed immediately (no per-section IO to spin up for these users).
    if (
        typeof window.matchMedia === 'function'
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        revealed.value = true
        return
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (entry.isIntersecting) reveal()
            }
        },
        { threshold: props.threshold },
    )
    observer.observe(root.value)
})

onUnmounted(() => {
    observer?.disconnect()
    observer = null
})
</script>

<template>
    <div
        ref="root"
        class="scroll-reveal"
        :class="{ 'scroll-reveal--visible': revealed }"
        :style="revealed && props.delay ? { transitionDelay: `${props.delay}ms` } : undefined"
    >
        <slot />
    </div>
</template>
