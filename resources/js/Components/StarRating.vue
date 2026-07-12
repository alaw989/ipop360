<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    rating: number | string;
    max?: number;
    size?: 'sm' | 'md' | 'lg';
    source?: 'Yelp' | 'Google' | null;
    reviewCount?: number | null;
}>();

const parsedRating = computed(() => Number(props.rating));

const starTypes = computed(() => {
    const rating = parsedRating.value;
    const max = props.max ?? 5;
    const full = Math.floor(rating);
    const half = rating - full >= 0.25 && full < max;
    const types: Array<'full' | 'half' | 'empty'> = [];
    for (let i = 0; i < max; i++) {
        if (i < full) types.push('full');
        else if (i === full && half) types.push('half');
        else types.push('empty');
    }
    return types;
});

const sizeClass = computed(() => {
    switch (props.size ?? 'md') {
        case 'sm': return 'text-sm';
        case 'lg': return 'text-xl';
        default: return 'text-base';
    }
});

const starViewBox = '0 0 20 20';
const starPath = 'M10 1l2.5 5.1L18 6.8l-4 3.9.9 5.5L10 13.3l-5 3.4L6 10.7l-4-3.9 5.5-.8z';
</script>

<template>
    <span class="inline-flex items-center gap-1" :class="sizeClass">
        <span class="inline-flex items-center gap-0.5">
            <template v-for="(type, i) in starTypes" :key="i">
                <svg
                    class="h-[1em] w-[1em]"
                    :class="type === 'empty' ? 'text-gray-300' : 'text-amber-400'"
                    :viewBox="starViewBox"
                    aria-hidden="true"
                >
                    <defs v-if="type === 'half'">
                        <linearGradient :id="'half-' + i + '-' + parsedRating.toFixed(1).replace('.', '-')">
                            <stop offset="50%" stop-color="currentColor" />
                            <stop offset="50%" stop-color="transparent" />
                        </linearGradient>
                    </defs>
                    <path
                        :d="starPath"
                        :fill="type === 'full' ? 'currentColor' : (type === 'half' ? `url(#half-${i}-${parsedRating.toFixed(1).replace('.', '-')})` : 'none')"
                        :stroke="type === 'empty' ? 'currentColor' : 'currentColor'"
                        :stroke-width="type === 'empty' ? '0.8' : '0.4'"
                    />
                </svg>
            </template>
        </span>
        <span class="font-medium text-foreground">{{ parsedRating.toFixed(1) }}</span>
        <span v-if="source" class="text-muted-foreground/60 text-[0.85em] font-medium">{{ source }}</span>
        <span v-if="reviewCount != null" class="text-muted-foreground/60 text-[0.85em] tabular-nums">
            ({{ reviewCount.toLocaleString() }})
        </span>
    </span>
</template>
