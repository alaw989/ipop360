<script setup lang="ts">
import { computed } from 'vue'
import { Head } from '@inertiajs/vue3'

// Renders JSON-LD via Inertia's Head so it's present in server-rendered HTML
// (the previous document.head.appendChild version was a client-only no-op
// during SSR). Angle brackets are escaped so dynamic data can't prematurely
// close the tag once this is serialized to an HTML string.
const props = defineProps<{
    data: Record<string, unknown> | Record<string, unknown>[] | null
}>()

const json = computed(() => {
    if (props.data == null) return null
    return JSON.stringify(props.data).replace(/</g, '\\u003c')
})
</script>

<template>
    <Head v-if="json">
        <component :is="'script'" type="application/ld+json">{{ json }}</component>
    </Head>
</template>
