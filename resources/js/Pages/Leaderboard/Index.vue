<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ScoreChip from '@/Components/ScoreChip.vue';
import StarRating from '@/Components/StarRating.vue';
import { ArrowUp, ArrowDown, Minus, Medal, TrendingUp } from '@lucide/vue';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    restaurants: {
        data: Restaurant[];
        current_page: number;
        last_page: number;
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    filters: Record<string, string>;
}>();

const items = computed(() => props.restaurants.data);

function rankBg(rank: number): string {
    if (rank === 1) return 'from-amber-400 to-yellow-500 text-white';
    if (rank === 2) return 'from-slate-300 to-slate-400 text-slate-900';
    if (rank === 3) return 'from-orange-400 to-amber-600 text-white';
    return 'from-gray-700 to-gray-800 text-white';
}

function rankChangeIcon(change: number | null): { icon: string; cls: string; label: string } | null {
    if (change == null) return null;
    if (change > 0) return { icon: 'up', cls: 'text-green-600', label: `+${change}` };
    if (change < 0) return { icon: 'down', cls: 'text-red-600', label: `${change}` };
    return { icon: 'steady', cls: 'text-muted-foreground', label: '0' };
}

function getDisplayRating(r: Restaurant): { rating: number; count: number; source: 'Yelp' | 'Google' } | null {
    if (r.yelp_rating) return { rating: r.yelp_rating, count: r.yelp_review_count, source: 'Yelp' };
    if (r.google_rating) return { rating: r.google_rating, count: r.google_review_count, source: 'Google' };
    return null;
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-8">
                <h1 class="text-3xl font-bold text-foreground">Restaurant Leaderboard</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Top-ranked dining spots, ordered by popularity score. Updated daily.
                </p>
            </div>

            <div class="space-y-2">
                <div
                    v-for="(r, i) in items"
                    :key="r.id"
                    class="group flex items-center gap-4 rounded-xl border bg-card p-4 transition-all hover:shadow-md hover:border-primary/20"
                >
                    <!-- Rank -->
                    <div class="flex w-12 shrink-0 flex-col items-center">
                        <div
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-r text-sm font-bold shadow-sm ring-2 ring-white/50"
                            :class="rankBg(i + 1)"
                        >
                            <span v-if="i === 0">👑</span>
                            <span v-else-if="i < 3" class="text-base">#{{ i + 1 }}</span>
                            <span v-else class="tabular-nums text-xs">{{ i + 1 }}</span>
                        </div>
                        <!-- Rank change -->
                        <div
                            v-if="rankChangeIcon(r.rank_change)"
                            class="mt-1 flex items-center gap-0.5 text-[10px] font-semibold tabular-nums"
                            :class="rankChangeIcon(r.rank_change)!.cls"
                        >
                            <ArrowUp v-if="rankChangeIcon(r.rank_change)!.icon === 'up'" class="h-2.5 w-2.5" />
                            <ArrowDown v-else-if="rankChangeIcon(r.rank_change)!.icon === 'down'" class="h-2.5 w-2.5" />
                            <Minus v-else class="h-2.5 w-2.5" />
                            {{ rankChangeIcon(r.rank_change)!.label }}
                        </div>
                    </div>

                    <!-- Photo -->
                    <div class="h-16 w-16 shrink-0 overflow-hidden rounded-xl">
                        <img
                            v-if="r.photo_url"
                            :src="r.photo_url"
                            :alt="r.name"
                            class="h-full w-full object-cover"
                            loading="lazy"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center bg-muted text-lg">
                            🍽
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <Link
                                :href="`/restaurants/${r.slug}`"
                                class="text-sm font-semibold text-foreground truncate hover:text-primary transition-colors"
                            >
                                {{ r.name }}
                            </Link>
                            <span v-if="r.has_award" class="shrink-0 text-[10px]">⭐</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <StarRating
                                v-if="getDisplayRating(r)"
                                :rating="getDisplayRating(r)!.rating"
                                :source="getDisplayRating(r)!.source"
                                :review-count="getDisplayRating(r)!.count"
                                                size="sm"
                            />
                            <span v-if="r.price_range" class="font-semibold text-emerald-500">{{ r.price_range }}</span>
                            <span v-if="r.cuisines.length > 0" class="truncate">
                                {{ r.cuisines.map(c => c.name).join(', ') }}
                            </span>
                            <span v-if="r.city">{{ r.city }}, {{ r.state }}</span>
                        </div>
                    </div>

                    <!-- Score -->
                    <div class="shrink-0">
                        <ScoreChip :total="r.popularity_score" :breakdown="r.score_breakdown ?? null" />
                    </div>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="restaurants.last_page > 1" class="mt-6 flex items-center justify-center gap-2">
                <Link
                    v-if="restaurants.current_page > 1"
                    :href="`/leaderboard?page=${restaurants.current_page - 1}`"
                    class="inline-flex h-9 items-center rounded-lg border bg-card px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                >
                    Previous
                </Link>
                <span class="text-xs text-muted-foreground">
                    Page {{ restaurants.current_page }} of {{ restaurants.last_page }}
                </span>
                <Link
                    v-if="restaurants.current_page < restaurants.last_page"
                    :href="`/leaderboard?page=${restaurants.current_page + 1}`"
                    class="inline-flex h-9 items-center rounded-lg border bg-card px-4 text-sm font-medium text-muted-foreground hover:text-foreground transition-colors"
                >
                    Next
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
