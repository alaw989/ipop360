<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { ChevronDown } from '@lucide/vue'
import StarRating from '@/Components/StarRating.vue'
import PriceLevel from '@/Components/PriceLevel.vue'
import { cuisineGradient } from '@/lib/cuisine'
import { photoBadge } from '@/lib/scoreTier'
interface PopularRestaurant {
    id: number
    name: string
    slug: string
    photo_url: string | null
    city?: string | null
    state?: string | null
    price_range: string | null
    google_rating: number | null
    google_review_count: number
    yelp_rating: number | null
    yelp_review_count: number
    has_award: boolean
    popularity_score: number
    score_breakdown?: {
        signals: Array<{ label: string; weight: number; normalized: number; contribution: number; detail?: string }>;
        total: number;
    } | null;
    cuisines: Array<{ id: number; name: string; slug: string }>
}

const props = defineProps<{
    restaurants: PopularRestaurant[]
    city: string | null
}>()

const showAll = ref(false)
const initialLimit = 12

// A freshly-picked city's list starts collapsed, not still expanded from
// whatever "Show more" state the previous city was left in.
watch(() => props.city, () => {
    showAll.value = false
})

// Tracks restaurants whose photo_url is present but failed to load, so a
// dead URL falls back to the placeholder instead of showing the browser's
// broken-image icon.
const brokenPhotoIds = ref<Set<number>>(new Set())

function markPhotoBroken(id: number): void {
    brokenPhotoIds.value = new Set(brokenPhotoIds.value).add(id)
}

const visibleRestaurants = computed(() =>
    showAll.value ? props.restaurants : props.restaurants.slice(0, initialLimit)
)

const hasMore = computed(() => props.restaurants.length > initialLimit)

function displayRating(r: PopularRestaurant) {
    if (r.yelp_rating) return { rating: Number(r.yelp_rating), count: r.yelp_review_count, source: 'Yelp' as const }
    if (r.google_rating) return { rating: Number(r.google_rating), count: r.google_review_count, source: 'Google' as const }
    return null
}

function primaryCuisine(r: PopularRestaurant) {
    return r.cuisines?.[0] ?? null
}

function gradient(r: PopularRestaurant): string {
    const slug = r.cuisines?.[0]?.slug
    return slug ? cuisineGradient(slug) : 'from-muted to-muted-foreground/20'
}
</script>

<template>
    <section class="w-full bg-muted/50 py-12">
        <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="mb-1 text-xl font-semibold text-foreground">
                Trending restaurants
                <span v-if="city"> in {{ city }}</span>
            </h2>
            <p class="mb-6 text-sm text-muted-foreground">
                {{ city ? 'Top-ranked dining spots right now' : 'Popular across iPop360' }}
            </p>

            <Transition name="restaurant-fade" mode="out-in">
                <div :key="city ?? 'global'" class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                    <a
                        v-for="(r, index) in visibleRestaurants"
                        :key="r.id"
                        :href="`/restaurants/${r.slug}`"
                        class="group relative flex flex-col overflow-hidden rounded-xl border bg-card transition-all hover:-translate-y-1 hover:shadow-lg"
                    >
                        <!-- Photo -->
                        <div class="relative aspect-[4/3] overflow-hidden">
                            <img
                                v-if="r.photo_url && !brokenPhotoIds.has(r.id)"
                                :src="r.photo_url"
                                :alt="r.name"
                                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                loading="lazy"
                                @error="markPhotoBroken(r.id)"
                            />
                            <div
                                v-else
                                class="flex h-full w-full flex-col items-center justify-center gap-1"
                                :class="gradient(r)"
                            >
                                <span class="text-4xl text-white/60">🍽</span>
                                <span class="text-xs font-medium text-white/60">Image coming soon</span>
                            </div>

                            <!-- At most one badge, solid and readable -->
                            <span
                                v-if="photoBadge(r)"
                                data-testid="photo-badge"
                                class="absolute left-2 top-2 rounded-md bg-primary px-2 py-1 text-xs font-semibold leading-none text-primary-foreground shadow-sm"
                            >{{ photoBadge(r) }}</span>
                        </div>

                        <!-- Details -->
                        <div class="flex flex-1 flex-col gap-1 p-3">
                            <div class="flex items-center gap-1.5">
                                <h3 class="text-sm font-semibold leading-tight text-foreground line-clamp-2">
                                    <span class="tabular-nums" data-testid="rank">{{ index + 1 }}.</span>
                                    {{ r.name }}
                                </h3>
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
                                <PriceLevel v-if="r.price_range" :price="r.price_range" />
                                <span v-if="r.price_range && primaryCuisine(r)" class="text-muted-foreground/40">•</span>
                                <span v-if="primaryCuisine(r)">{{ primaryCuisine(r)!.name }}</span>
                            </div>
                        </div>
                    </a>
                </div>
            </Transition>

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
        </div>
    </section>
</template>

<style scoped>
.restaurant-fade-enter-active,
.restaurant-fade-leave-active {
    transition: opacity 0.2s ease;
}
.restaurant-fade-enter-from,
.restaurant-fade-leave-to {
    opacity: 0;
}
</style>
