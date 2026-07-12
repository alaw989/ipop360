<script setup lang="ts">
import { ref, computed } from 'vue'
import { ChevronDown } from '@lucide/vue'
import StarRating from '@/Components/StarRating.vue'
import ScoreChip from '@/Components/ScoreChip.vue'
import RestaurantCardSkeleton from '@/Components/RestaurantCardSkeleton.vue'
import { cuisineGradient, FOOD_FALLBACK_GRADIENT } from '@/lib/cuisine'

interface Cuisine {
    id: number
    name: string
    slug: string
}

interface Restaurant {
    id: number
    name: string
    slug: string
    photo_url: string | null
    city: string | null
    state: string | null
    price_range: string | null
    google_rating: number | null
    google_review_count: number
    yelp_rating: number | null
    yelp_review_count: number
    has_award: boolean
    popularity_score: number
    latitude: number | null
    longitude: number | null
    cuisines: Cuisine[]
}

const props = defineProps<{
    restaurants: Restaurant[]
    city: string | null
    loading?: boolean
}>()

const showAll = ref(false)
const initialLimit = 12

const visibleRestaurants = computed(() =>
    showAll.value ? props.restaurants : props.restaurants.slice(0, initialLimit)
)

const hasMore = computed(() => props.restaurants.length > initialLimit)

function displayRating(r: Restaurant) {
    if (r.yelp_rating) return { rating: Number(r.yelp_rating), count: r.yelp_review_count, source: 'Yelp' as const }
    if (r.google_rating) return { rating: Number(r.google_rating), count: r.google_review_count, source: 'Google' as const }
    return null
}

function primaryCuisine(r: Restaurant): Cuisine | null {
    return r.cuisines?.[0] ?? null
}

function gradient(r: Restaurant): string {
    const slug = primaryCuisine(r)?.slug
    return slug ? cuisineGradient(slug) : FOOD_FALLBACK_GRADIENT
}

function rankBadge(rank: number) {
    if (rank === 1) return { bg: 'from-amber-400 to-yellow-500', text: 'text-white', icon: '🔥' }
    if (rank === 2) return { bg: 'from-slate-300 to-slate-400', text: 'text-slate-900', icon: '#2' }
    if (rank === 3) return { bg: 'from-orange-400 to-amber-600', text: 'text-white', icon: '#3' }
    return null
}
</script>

<template>
    <section class="mx-auto w-full max-w-7xl px-4 py-12 sm:px-6 lg:px-8">
        <h2 class="mb-1 text-xl font-semibold text-foreground">
            Trending restaurants
            <span v-if="city"> in {{ city }}</span>
        </h2>
        <p class="mb-6 text-sm text-muted-foreground">
            Top-ranked dining spots right now
        </p>

        <div v-if="loading" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <RestaurantCardSkeleton v-for="i in 8" :key="'skeleton-' + i" />
        </div>
        <template v-else>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <a
                    v-for="(r, index) in visibleRestaurants"
                    :key="r.id"
                    :href="`/restaurants/${r.slug}`"
                    class="group relative flex flex-col overflow-hidden rounded-xl border bg-card transition-all hover:-translate-y-1 hover:shadow-lg"
                >
                    <!-- Photo -->
                    <div class="relative aspect-[4/3] overflow-hidden">
                        <img
                            v-if="r.photo_url"
                            :src="r.photo_url"
                            :alt="r.name"
                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                            loading="lazy"
                        />
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center"
                            :class="gradient(r)"
                        >
                            <span class="text-4xl text-white/60">🍽</span>
                        </div>

                        <!-- Rank badge (top 3) -->
                        <div
                            v-if="rankBadge(index + 1)"
                            class="absolute left-2 top-2"
                        >
                            <div
                                class="flex h-7 min-w-[28px] items-center justify-center rounded-full bg-gradient-to-r px-2 text-[11px] font-bold shadow-lg ring-2 ring-white/50"
                                :class="[rankBadge(index + 1)!.bg, rankBadge(index + 1)!.text]"
                            >
                                {{ rankBadge(index + 1)!.icon }}
                            </div>
                        </div>

                        <!-- Score chip -->
                        <div v-if="r.popularity_score > 0" class="absolute right-2 top-2">
                            <ScoreChip :total="r.popularity_score" />
                        </div>
                    </div>

                    <!-- Details -->
                    <div class="flex flex-1 flex-col gap-1 p-3">
                        <div class="flex items-center gap-1.5">
                            <h3 class="text-sm font-semibold leading-tight text-foreground line-clamp-2">
                                {{ r.name }}
                            </h3>
                            <span v-if="r.has_award" class="shrink-0 inline-flex items-center gap-0.5 rounded-full bg-amber-400/20 px-1.5 py-0.5 text-[9px] font-semibold text-amber-600">
                                ⭐
                            </span>
                        </div>

                        <div class="flex items-center gap-1 text-xs text-muted-foreground">
                            <StarRating
                                v-if="displayRating(r)"
                                :rating="displayRating(r)!.rating"
                                :source="displayRating(r)!.source"
                                :review-count="displayRating(r)!.count"
                                size="sm"
                            />
                        </div>

                        <div class="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span v-if="r.price_range" class="tabular-nums">{{ r.price_range }}</span>
                            <span v-if="r.price_range && primaryCuisine(r)" class="text-muted-foreground/40">•</span>
                            <span v-if="primaryCuisine(r)">{{ primaryCuisine(r)!.name }}</span>
                        </div>
                    </div>
                </a>
            </div>

            <button
                v-if="hasMore"
                class="mt-6 flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                @click="showAll = !showAll"
            >
                <ChevronDown
                    class="h-4 w-4 transition-transform duration-200"
                    :class="showAll ? 'rotate-180' : ''"
                />
                <span>{{ showAll ? 'Show less' : 'Show more' }}</span>
            </button>
        </template>
    </section>
</template>
