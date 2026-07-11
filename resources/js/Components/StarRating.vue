<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    rating: number | string;
    max?: number;
    size?: 'sm' | 'md' | 'lg';
}>();

const parsedRating = computed(() => Number(props.rating));

const stars = computed(() => {
    const rating = parsedRating.value;
    const max = props.max ?? 5;
    const full = Math.floor(rating);
    const half = rating - full >= 0.25;
    const empty = max - full - (half ? 1 : 0);
    return { full, half, empty };
});

const sizeClass = computed(() => {
    switch (props.size ?? 'md') {
        case 'sm': return 'text-sm';
        case 'lg': return 'text-xl';
        default: return 'text-base';
    }
});
</script>

<template>
    <span class="inline-flex items-center gap-0.5" :class="sizeClass">
        <span v-for="i in stars.full" :key="'f' + i" class="text-amber-400">★</span>
        <span v-if="stars.half" class="relative inline-block text-gray-300">
            <span class="absolute inset-0 overflow-hidden text-amber-400" style="width: 50%;">★</span>
            ★
        </span>
        <span v-for="i in stars.empty" :key="'e' + i" class="text-gray-300">★</span>
        <span class="ml-1 text-sm font-medium text-foreground">{{ parsedRating.toFixed(1) }}</span>
    </span>
</template>
