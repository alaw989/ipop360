<script setup lang="ts">
import { computed } from 'vue';
import { Star, BadgeCheck, Flame, TrendingUp } from '@lucide/vue';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';

const props = defineProps<{
    total: number | string;
    breakdown?: {
        signals: Array<{
            label: string;
            weight: number;
            normalized: number;
            contribution: number;
            detail?: string;
        }>;
        total: number;
    } | null;
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

const topSignals = computed(() => {
    if (!props.breakdown?.signals) return [];
    return props.breakdown.signals
        .filter(s => s.contribution > 0)
        .sort((a, b) => b.contribution - a.contribution)
        .slice(0, 4);
});

const segmentColors: Record<string, string> = {
    'Quality': 'bg-red-500',
    'Proximity': 'bg-green-500',
    'Profile Completeness': 'bg-emerald-500',
    'Award': 'bg-purple-500',
    'Cuisine Match': 'bg-fuchsia-500',
    'Social Presence': 'bg-pink-500',
    'Website Traffic': 'bg-blue-500',
    'Page Views': 'bg-cyan-500',
    'Social Link Clicks': 'bg-amber-500',
    'Menu Clicks': 'bg-orange-500',
};

function signalColor(label: string): string {
    return segmentColors[label] ?? 'bg-gray-400';
}
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <span
                v-if="tier"
                class="inline-flex cursor-pointer items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums shadow-sm ring-1 ring-white/30 backdrop-blur-sm transition-colors hover:opacity-80"
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
        </PopoverTrigger>
        <PopoverContent
            v-if="topSignals.length > 0"
            side="top"
            align="center"
            class="w-56 rounded-xl border bg-card p-3 shadow-xl"
        >
            <div class="mb-2 flex items-center justify-between">
                <span class="text-xs font-semibold text-foreground">Score Breakdown</span>
                <span class="text-xs font-bold tabular-nums text-primary">{{ pct }}%</span>
            </div>
            <div class="mb-2 flex h-1.5 w-full overflow-hidden rounded-full bg-muted">
                <div
                    v-for="(s, i) in topSignals"
                    :key="s.label"
                    :style="{ width: Math.max((s.contribution / (breakdown?.total ?? 1)) * 100, 3) + '%' }"
                    :class="[signalColor(s.label), i === 0 ? 'rounded-l-full' : '', i === topSignals.length - 1 ? 'rounded-r-full' : '']"
                    class="h-full transition-all"
                />
            </div>
            <div class="space-y-1">
                <div
                    v-for="s in topSignals"
                    :key="s.label"
                    class="flex items-center gap-2 text-[11px]"
                >
                    <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full" :class="signalColor(s.label)" />
                    <span class="flex-1 truncate text-muted-foreground">{{ s.label }}</span>
                    <span class="font-medium tabular-nums text-foreground">{{ Math.round(s.contribution * 100) }}%</span>
                </div>
            </div>
            <p class="mt-1.5 text-[10px] leading-tight text-muted-foreground/60">
                Based on Google ratings, proximity, awards, and data completeness.
            </p>
        </PopoverContent>
    </Popover>
</template>
