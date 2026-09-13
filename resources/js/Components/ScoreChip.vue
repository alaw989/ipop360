<script setup lang="ts">
import { computed } from 'vue';
import { Star, BadgeCheck, Flame, TrendingUp, Info } from '@lucide/vue';
import { Popover, PopoverTrigger, PopoverContent } from '@/components/ui/popover';
import { scoreTier } from '@/lib/scoreTier';

// The popularity tier ("Popular") that opens the score breakdown. 'chip' is a
// solid, readable pill for lists (it never sits on a photo); 'link' is a quiet
// "Why it ranks here" for result cards, whose photo badge already names the
// top tiers. No percentage: the score is internal; the breakdown explains it.
const props = withDefaults(defineProps<{
    total: number | string;
    variant?: 'chip' | 'link';
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
}>(), {
    variant: 'chip',
});

const tier = computed(() => scoreTier(props.total));

const tierIcon = computed(() => {
    switch (tier.value?.key) {
        case 'elite': return Star;
        case 'top': return BadgeCheck;
        case 'popular': return Flame;
        default: return TrendingUp;
    }
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
            <button
                v-if="variant === 'link'"
                v-show="topSignals.length > 0"
                type="button"
                class="relative z-10 inline-flex min-h-8 items-center gap-1 text-[13px] text-muted-foreground underline-offset-2 transition-colors hover:text-foreground hover:underline"
                data-testid="score-why"
            >
                <Info class="h-3.5 w-3.5" aria-hidden="true" />
                Why it ranks here
            </button>
            <button
                v-else-if="tier"
                type="button"
                class="relative z-10 inline-flex cursor-pointer items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-foreground ring-1 ring-border transition-colors hover:bg-accent"
                data-testid="score-chip"
            >
                <component :is="tierIcon" class="h-3 w-3 text-muted-foreground" :class="{ 'fill-current': tier.key === 'elite' }" aria-hidden="true" />
                {{ tier.label }}
            </button>
        </PopoverTrigger>
        <PopoverContent
            v-if="topSignals.length > 0"
            side="top"
            align="center"
            class="w-56 rounded-xl border bg-card p-3 shadow-xl"
        >
            <p class="mb-2 text-xs font-semibold text-foreground">Why it ranks here</p>
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
            <p class="mt-1.5 text-[10px] leading-tight text-muted-foreground">
                Based on Google ratings, proximity, awards, and data completeness.
            </p>
        </PopoverContent>
    </Popover>
</template>
