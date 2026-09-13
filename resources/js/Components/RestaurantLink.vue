<script setup lang="ts">
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { getDetailUrl } from '@/composables/useRestaurantDisplay'
import type { Restaurant } from '@/types/restaurant'

// The link to a restaurant's page. A saved restaurant opens inside the app,
// with no full page load, and its page is fetched while the pointer rests on
// the link, so it opens at once. A live result opens its preview or Google
// Maps in a new tab.
const props = defineProps<{
    restaurant: Restaurant
}>()

const href = computed(() => getDetailUrl(props.restaurant))
</script>

<template>
    <Link v-if="restaurant.id > 0" :href="href" prefetch><slot /></Link>
    <a v-else :href="href" target="_blank" rel="noopener"><slot /></a>
</template>
