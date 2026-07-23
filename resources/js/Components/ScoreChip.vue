<script setup lang="ts">
import { computed } from 'vue';
import { Star, BadgeCheck, Flame, TrendingUp } from '@lucide/vue';

const props = defineProps<{
    total: number | string;
}>();

const score = computed(() => Number(props.total ?? 0));
const pct = computed(() => Math.round(score.value * 100));

const tier = computed(() => {
    const t = score.value;
    if (t >= 0.9) return { label: 'Elite', classes: 'bg-amber-500/40 text-amber-600 dark:text-amber-400' };
    if (t >= 0.8) return { label: 'Top Rated', classes: 'bg-emerald-500/40 text-emerald-600 dark:text-emerald-400' };
    if (t >= 0.6) return { label: 'Popular', classes: 'bg-sky-500/40 text-sky-600 dark:text-sky-400' };
    if (t >= 0.4) return { label: 'Rising', classes: 'bg-teal-500/40 text-teal-600 dark:text-teal-400' };
    return null;
});
</script>

<template>
    <span
        v-if="tier"
        title="Based on Google ratings, proximity, awards, and data completeness."
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums shadow-sm ring-1 ring-white/30 backdrop-blur-sm"
        :class="tier.classes"
    >
        <template v-if="score >= 0.9">
            <Star class="h-3 w-3 fill-current" />
        </template>
        <template v-else-if="score >= 0.8">
            <BadgeCheck class="h-3 w-3" />
        </template>
        <template v-else-if="score >= 0.6">
            <Flame class="h-3 w-3" />
        </template>
        <template v-else>
            <TrendingUp class="h-3 w-3" />
        </template>
        {{ tier.label }}
        <span class="opacity-60">&bull; {{ pct }}%</span>
    </span>
</template>
