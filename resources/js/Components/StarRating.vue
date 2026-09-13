<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    rating: number | string;
    max?: number;
    size?: 'sm' | 'md' | 'lg';
    source?: 'Yelp' | 'Google' | null;
    reviewCount?: number | null;
    // Below 640px show just "(210)", keeping the full "210 Google reviews" for
    // wider screens (and in the title).
    compactOnPhone?: boolean;
}>();

const parsedRating = computed(() => Number(props.rating));

const starTypes = computed(() => {
    const max = props.max ?? 5;
    const rating = Math.min(parsedRating.value, max);
    const fullBase = Math.floor(rating);
    const frac = rating - fullBase;
    const full = frac > 0.75 ? fullBase + 1 : fullBase;
    const half = frac > 0.25 && frac <= 0.75 && full < max;
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

// "210 Google reviews", "Google", or "210 reviews". The ratings come from
// Google, and saying so keeps anyone from reading them as Yelp reviews.
const countLabel = computed(() => {
    const count = props.reviewCount;
    if (count == null) return props.source ?? null;
    const noun = count === 1 ? 'review' : 'reviews';
    return props.source
        ? `${count.toLocaleString()} ${props.source} ${noun}`
        : `${count.toLocaleString()} ${noun}`;
});

const halfId = (i: number) => `half-${i}-${parsedRating.value.toFixed(1).replace('.', '-')}`;

const starViewBox = '0 0 20 20';
const starPath = 'M10 1l2.5 5.1L18 6.8l-4 3.9.9 5.5L10 13.3l-5 3.4L6 10.7l-4-3.9 5.5-.8z';
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-x-1.5" :class="sizeClass">
        <span class="inline-flex items-center gap-px" aria-hidden="true">
            <svg
                v-for="(type, i) in starTypes"
                :key="i"
                class="h-[1em] w-[1em]"
                :class="type === 'empty' ? 'text-border' : 'text-rating'"
                :data-star="type"
                :viewBox="starViewBox"
            >
                <defs v-if="type === 'half'">
                    <linearGradient :id="halfId(i)">
                        <stop offset="50%" stop-color="currentColor" />
                        <stop offset="50%" style="stop-color: var(--border)" />
                    </linearGradient>
                </defs>
                <path :d="starPath" :fill="type === 'half' ? `url(#${halfId(i)})` : 'currentColor'" />
            </svg>
        </span>
        <span class="font-semibold text-foreground tabular-nums">{{ parsedRating.toFixed(1) }}<span class="sr-only"> out of {{ max ?? 5 }}</span></span>
        <template v-if="countLabel">
            <span
                v-if="compactOnPhone && reviewCount != null"
                class="text-[0.9em] text-muted-foreground tabular-nums sm:hidden"
                :title="countLabel"
            >({{ reviewCount.toLocaleString() }})</span>
            <span
                class="text-[0.9em] text-muted-foreground tabular-nums"
                :class="{ 'hidden sm:inline': compactOnPhone && reviewCount != null }"
            >{{ countLabel }}</span>
        </template>
    </span>
</template>
