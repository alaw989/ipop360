<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ScoreChip from '@/Components/ScoreChip.vue';
import StarRating from '@/Components/StarRating.vue';
import { ArrowLeft } from '@lucide/vue';
import type { Restaurant, ScoreSignal } from '@/types/restaurant';

const props = defineProps<{
    restaurants: Restaurant[];
}>();

const items = computed(() => props.restaurants);

const allSignalLabels = computed(() => {
    const labels = new Set<string>();
    for (const r of items.value) {
        for (const s of r.score_breakdown?.signals ?? []) {
            if (s.contribution > 0) labels.add(s.label);
        }
    }
    return Array.from(labels);
});

function signalFor(restaurant: Restaurant, label: string): ScoreSignal | undefined {
    return restaurant.score_breakdown?.signals?.find(s => s.label === label && s.contribution > 0);
}

function pct(v: number): string {
    return (v * 100).toFixed(1) + '%';
}

function hasMax(restaurant: Restaurant, label: string): boolean {
    const s = signalFor(restaurant, label);
    if (!s) return false;
    return items.value.every(other => {
        const o = signalFor(other, label);
        return !o || s.contribution >= o.contribution;
    });
}

function getDisplayRating(r: Restaurant): { rating: number; count: number; source: 'Yelp' | 'Google' } | null {
    if (r.yelp_rating) return { rating: r.yelp_rating, count: r.yelp_review_count, source: 'Yelp' };
    if (r.google_rating) return { rating: r.google_rating, count: r.google_review_count, source: 'Google' };
    return null;
}

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

function signalDot(label: string): string {
    return segmentColors[label] ?? 'bg-gray-400';
}

const hasScoreData = computed(() => items.value.some(r => (r.score_breakdown?.signals?.length ?? 0) > 0));
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-6 flex items-center gap-4">
                <Link
                    href="/restaurants"
                    class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition-colors"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Back
                </Link>
                <div>
                    <h1 class="text-2xl font-bold text-foreground">Compare Restaurants</h1>
                    <p v-if="items.length > 0" class="text-sm text-muted-foreground">
                        Side-by-side comparison of {{ items.length }} restaurants
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        No restaurants selected. Go to the
                        <Link href="/restaurants" class="text-primary hover:underline">browse page</Link>
                        and click "Compare" on restaurants you want to compare.
                    </p>
                </div>
            </div>

            <div v-if="items.length === 0" class="flex flex-col items-center justify-center py-20">
                <span class="text-6xl">🔍</span>
                <p class="mt-4 text-muted-foreground">Select restaurants from the browse page to compare them.</p>
            </div>

            <!-- Score Overview Bar -->
            <div v-if="items.length > 0" class="mb-8 overflow-x-auto">
                <div class="flex gap-4" :style="{ minWidth: items.length * 280 + 'px' }">
                    <div
                        v-for="r in items"
                        :key="r.id"
                        class="flex-1 rounded-xl border bg-card p-4 text-center"
                    >
                        <div class="mx-auto mb-2 h-24 w-24 overflow-hidden rounded-xl">
                            <img
                                v-if="r.photo_url"
                                :src="r.photo_url"
                                :alt="r.name"
                                class="h-full w-full object-cover"
                                loading="lazy"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center bg-muted text-2xl">🍽</div>
                        </div>
                        <Link
                            :href="`/restaurants/${r.slug}`"
                            class="text-sm font-semibold text-foreground hover:text-primary transition-colors"
                        >
                            {{ r.name }}
                        </Link>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ r.city }}, {{ r.state }}
                        </div>
                        <div class="mt-2 flex justify-center">
                            <ScoreChip :total="r.popularity_score" :breakdown="r.score_breakdown ?? null" />
                        </div>
                        <div class="mt-1 flex justify-center">
                            <StarRating
                                v-if="getDisplayRating(r)"
                                :rating="getDisplayRating(r)!.rating"
                                :source="getDisplayRating(r)!.source"
                                :review-count="getDisplayRating(r)!.count"
                                size="sm"
                            />
                        </div>
                        <div class="mt-1 text-xs text-muted-foreground">
                            {{ r.price_range ?? '—' }}
                            <span v-if="r.cuisines.length > 0">
                                &middot; {{ r.cuisines.map(c => c.name).join(', ') }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Score Breakdown Comparison Table -->
            <div v-if="hasScoreData" class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-border bg-muted/50">
                            <th class="px-4 py-3 font-semibold text-foreground">Signal</th>
                            <th
                                v-for="r in items"
                                :key="r.id"
                                class="px-4 py-3 font-semibold text-foreground"
                            >
                                {{ r.name.length > 20 ? r.name.slice(0, 20) + '…' : r.name }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="label in allSignalLabels" :key="label" class="border-b border-border last:border-0">
                            <td class="flex items-center gap-2 px-4 py-3 font-medium text-foreground">
                                <span class="inline-block h-2 w-2 rounded-full" :class="signalDot(label)" />
                                {{ label }}
                            </td>
                            <td
                                v-for="r in items"
                                :key="r.id"
                                class="px-4 py-3 tabular-nums"
                                :class="hasMax(r, label) ? 'text-green-600 dark:text-green-400 font-semibold' : 'text-muted-foreground'"
                            >
                                <template v-if="signalFor(r, label)">
                                    {{ pct(signalFor(r, label)!.contribution) }}
                                </template>
                                <span v-else class="text-muted-foreground/50">—</span>
                            </td>
                        </tr>
                        <!-- Summary row -->
                        <tr class="border-t-2 border-border bg-muted/30">
                            <td class="px-4 py-3 font-semibold text-foreground">Total Score</td>
                            <td
                                v-for="r in items"
                                :key="r.id"
                                class="px-4 py-3 font-bold tabular-nums text-primary"
                            >
                                {{ Math.round(r.popularity_score * 100) }}%
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Empty state for no breakdown data -->
            <div v-else-if="items.length > 0" class="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">
                Score breakdown data is not available for these restaurants.
            </div>
        </div>
    </AppLayout>
</template>
