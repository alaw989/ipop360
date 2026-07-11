<script setup lang="ts">
import { ref } from 'vue'

/**
 * The iPop360 logo: the brand orbit-ring (from the original artwork) over an
 * "ipop360" wordmark.
 *
 * The ring is the real raster mark; the wordmark is rendered text so it stays
 * prominent at every size. `:wordmark="false"` renders the mark alone.
 *
 * Scales as a unit via the inherited font-size: the ring's height tracks 1em and
 * the wordmark ~0.38em, so set a font-size on the element (e.g. class="text-2xl")
 * to size the whole lockup.
 */
withDefaults(defineProps<{ wordmark?: boolean }>(), {
    wordmark: true,
})

const imgLoaded = ref(true)

function onImgError() {
    imgLoaded.value = false
}
</script>

<template>
    <span class="inline-flex flex-col items-center leading-none">
        <img
            v-show="imgLoaded"
            src="/img/ipop360-mark.png"
            alt="iPop360"
            class="h-[1em] w-auto select-none"
            draggable="false"
            @error="onImgError"
        />
        <span
            v-if="wordmark && imgLoaded"
            class="mt-[0.1em] font-semibold tracking-tight"
            style="font-size: 0.38em"
        >iPop360</span>
        <span
            v-if="!imgLoaded"
            class="mt-[0.1em] font-semibold tracking-tight"
        >iPop360</span>
    </span>
</template>
