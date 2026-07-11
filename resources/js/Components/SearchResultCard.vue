<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import StarRating from '@/Components/StarRating.vue';
import ScoreChip from '@/Components/ScoreChip.vue';
import { Badge } from '@/components/ui/badge';
import { Heart, Navigation, Phone, Globe } from '@lucide/vue';
import { useFavorites } from '@/composables/useFavorites';
import { callPhone, openWebsite, trackDirections, mapsUrl } from '@/lib/restaurant';
import { cuisineGradient, FOOD_FALLBACK_GRADIENT } from '@/lib/cuisine';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    restaurant: Restaurant;
    rank: number;
    searchLat?: number | null;
    searchLng?: number | null;
}>();

const { isFavorited, toggle } = useFavorites();

const detailOrMapsUrl = computed(() => {
    if (props.restaurant.id > 0) {
        return `/restaurants/${props.restaurant.slug}`;
    }
    if (props.restaurant.slug) {
        return `/restaurants/preview/${props.restaurant.slug}`;
    }
    return mapsUrl(props.restaurant.name, props.restaurant.city);
});

const displayRating = computed(() => {
    if (props.restaurant.yelp_rating) return { rating: props.restaurant.yelp_rating, count: props.restaurant.yelp_review_count, source: 'Yelp' };
    if (props.restaurant.google_rating) return { rating: props.restaurant.google_rating, count: props.restaurant.google_review_count, source: 'Google' };
    return null;
});

const mapCoords = computed(() => {
    if (props.restaurant.lat != null && props.restaurant.lng != null) {
        return { lat: props.restaurant.lat, lng: props.restaurant.lng };
    }
    return null;
});

const saved = computed(() => isFavorited(props.restaurant));

const rankStyle = computed(() => {
    if (props.rank === 1) return { bg: 'from-amber-400 to-yellow-500', text: 'text-white' };
    if (props.rank === 2) return { bg: 'from-slate-300 to-slate-400', text: 'text-slate-900' };
    if (props.rank === 3) return { bg: 'from-orange-400 to-amber-600', text: 'text-white' };
    return { bg: 'from-muted to-muted-foreground/20', text: 'text-muted-foreground' };
});

const reviewSnippet = computed(() => {
    if (!props.restaurant.description) return null;
    return props.restaurant.description.length > 120
        ? props.restaurant.description.slice(0, 120) + '…'
        : props.restaurant.description;
});

const gradient = computed(() => {
    const primaryCuisine = props.restaurant.cuisines[0]?.slug;
    return primaryCuisine ? cuisineGradient(primaryCuisine) : FOOD_FALLBACK_GRADIENT;
});
</script>

<template>
    <article class="group relative flex overflow-hidden rounded-xl border bg-card transition-shadow hover:shadow-md">
        <!-- Photo section -->
        <div class="relative h-44 w-44 shrink-0 overflow-hidden">
            <div v-if="restaurant.photo_url" class="h-full w-full">
                <img
                    :src="restaurant.photo_url"
                    :alt="restaurant.name"
                    class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                    loading="lazy"
                />
            </div>
            <div v-else class="flex h-full w-full items-center justify-center" :class="gradient">
                <span class="text-4xl text-white/60">🍽</span>
            </div>

            <!-- Rank badge -->
            <div class="absolute left-2 top-2">
                <div
                    class="flex h-8 min-w-[32px] items-center justify-center rounded-full bg-gradient-to-r px-2.5 text-xs font-bold shadow-lg ring-2 ring-white/50"
                    :class="[rankStyle.bg, rankStyle.text]"
                >
                    <span v-if="rank === 1">🔥</span>
                    <span v-else class="tabular-nums">#{{ rank }}</span>
                </div>
            </div>

            <!-- ScoreChip -->
            <div v-if="restaurant.popularity_score != null" class="absolute bottom-2 left-2">
                <ScoreChip :total="restaurant.popularity_score" />
            </div>
        </div>

        <!-- Details section -->
        <div class="flex flex-1 flex-col justify-between p-4 min-w-0">
            <div class="space-y-1.5">
                <!-- Name + address -->
                <div>
                    <h3 class="text-base font-semibold leading-tight text-foreground transition-colors group-hover:text-primary">
                        <a
                            :href="detailOrMapsUrl"
                            :target="restaurant.id > 0 ? undefined : '_blank'"
                            :rel="restaurant.id > 0 ? undefined : 'noopener'"
                            class="after:absolute after:inset-0 after:z-0"
                        >
                            {{ restaurant.name }}
                        </a>
                    </h3>
                    <p v-if="restaurant.address || restaurant.city" class="truncate text-xs text-muted-foreground">
                        {{ [restaurant.address, restaurant.city, restaurant.state].filter(Boolean).join(', ') }}
                    </p>
                </div>

                <!-- Rating + reviews + price + distance -->
                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                    <StarRating
                        v-if="displayRating"
                        :rating="displayRating.rating"
                        size="sm"
                    />
                    <span v-if="displayRating" class="text-xs tabular-nums text-muted-foreground">
                        {{ displayRating.count.toLocaleString() }} reviews
                    </span>
                    <span v-if="restaurant.price_range" class="text-sm font-semibold text-emerald-500 dark:text-emerald-400">
                        {{ restaurant.price_range }}
                    </span>
                    <span v-if="restaurant.distance != null" class="text-xs text-muted-foreground">
                        {{ Number(restaurant.distance).toFixed(1) }} km
                    </span>
                </div>

                <!-- Review snippet -->
                <p v-if="reviewSnippet" class="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                    "{{ reviewSnippet }}"
                    <Link
                        :href="detailOrMapsUrl"
                        class="relative z-10 whitespace-nowrap font-medium text-primary hover:underline"
                    >
                        Read more
                    </Link>
                </p>

                <!-- Cuisine badges -->
                <div v-if="restaurant.cuisines.length > 0" class="flex flex-wrap gap-1.5 pt-0.5">
                    <Link
                        v-for="cuisine in restaurant.cuisines"
                        :key="cuisine.id"
                        :href="`/search?cuisine=${cuisine.slug}`"
                        class="relative z-10"
                    >
                        <Badge
                            variant="secondary"
                            class="bg-primary/5 text-[11px] font-medium text-primary/70 hover:bg-primary/10 cursor-pointer"
                        >
                            {{ cuisine.name }}
                        </Badge>
                    </Link>
                </div>
            </div>

            <!-- Action pills -->
            <div class="flex items-center gap-2 pt-2">
                <a
                    v-if="mapCoords"
                    :href="`https://www.google.com/maps/dir/?api=1&destination=${mapCoords.lat},${mapCoords.lng}`"
                    target="_blank"
                    rel="noopener"
                    class="relative z-10 inline-flex min-h-[36px] items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    title="Get directions"
                    @click.stop="trackDirections(restaurant.id)"
                >
                    <Navigation class="h-3 w-3" />
                    <span>Directions</span>
                </a>
                <button
                    v-if="restaurant.phone"
                    class="relative z-10 inline-flex min-h-[36px] items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    :title="`Call ${restaurant.phone}`"
                    @click.stop="callPhone(restaurant.phone, restaurant.id)"
                >
                    <Phone class="h-3 w-3" />
                    <span>Call</span>
                </button>
                <button
                    v-if="restaurant.website_url"
                    class="relative z-10 inline-flex min-h-[36px] items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    title="Visit website"
                    @click.stop="openWebsite(restaurant.website_url, restaurant.id)"
                >
                    <Globe class="h-3 w-3" />
                    <span>Website</span>
                </button>

                <!-- Heart -->
                <button
                    class="relative z-10 ml-auto flex h-9 w-9 items-center justify-center rounded-full transition-all hover:bg-muted"
                    :class="{ 'text-red-500': saved }"
                    :aria-label="saved ? 'Saved' : 'Save restaurant'"
                    @click.stop="() => toggle(restaurant)"
                >
                    <Heart
                        class="h-4 w-4"
                        :class="saved ? 'fill-current' : 'fill-none stroke-current'"
                    />
                </button>
            </div>
        </div>
    </article>
</template>
