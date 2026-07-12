<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import StarRating from '@/Components/StarRating.vue';
import CardGallery from '@/Components/CardGallery.vue';
import ScoreChip from '@/Components/ScoreChip.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Restaurant } from '@/types/restaurant';
import { cuisineGradient, FOOD_FALLBACK_GRADIENT } from '@/lib/cuisine';
import { callPhone, openWebsite, trackDirections, mapsUrl } from '@/lib/restaurant';
import { Phone, Globe, Navigation, Heart } from '@lucide/vue';
import { useFavorites } from '@/composables/useFavorites';

const props = defineProps<{
    restaurant: Restaurant;
    rank: number;
    searchLat?: number | null;
    searchLng?: number | null;
    cuisine?: string;
    stagger?: boolean;
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

const isTop3 = computed(() => props.rank <= 3);

const rankStyle = computed(() => {
    if (props.rank === 1) return { bg: 'from-amber-400 to-yellow-500', text: 'text-white', ring: 'shadow-amber-500/30' };
    if (props.rank === 2) return { bg: 'from-slate-300 to-slate-400', text: 'text-slate-900', ring: 'shadow-slate-400/30' };
    if (props.rank === 3) return { bg: 'from-orange-400 to-amber-600', text: 'text-white', ring: 'shadow-orange-500/30' };
    return { bg: 'from-muted to-muted-foreground/20', text: 'text-muted-foreground', ring: '' };
});

const rankLabel = computed(() => {
    if (props.rank === 1) return '1';
    return String(props.rank);
});

const photos = computed(() => {
    const unique = Array.from(new Set([props.restaurant.photo_url, ...(props.restaurant.photos ?? [])].filter(Boolean))) as string[];
    return unique.slice(0, 6);
});

const gradient = computed(() => {
    const primaryCuisine = props.restaurant.cuisines[0]?.slug;
    return primaryCuisine ? cuisineGradient(primaryCuisine) : FOOD_FALLBACK_GRADIENT;
});

const displayRating = computed(() => {
    if (props.restaurant.yelp_rating) return { rating: props.restaurant.yelp_rating, count: props.restaurant.yelp_review_count, source: 'Yelp' as const };
    if (props.restaurant.google_rating) return { rating: props.restaurant.google_rating, count: props.restaurant.google_review_count, source: 'Google' as const };
    return null;
});

const mapCoords = computed(() => {
    if (props.restaurant.lat != null && props.restaurant.lng != null) {
        return { lat: props.restaurant.lat, lng: props.restaurant.lng };
    }
    return null;
});

const saved = computed(() => isFavorited(props.restaurant));

const ariaLabel = computed(() => (saved.value ? 'Saved' : 'Save restaurant'));
</script>

<template>
    <article
        :class="[stagger && rank <= 12 ? 'card-enter' : '', 'cv-card']"
        :style="{ '--rank': rank }"
        class="group relative overflow-hidden rounded-2xl transition-[transform,box-shadow,border-color] duration-300 ease-out hover:-translate-y-1 hover:border-primary/30 hover:shadow-xl bg-card border"
    >
        <CardGallery
            :photos="photos"
            :gradient="gradient"
            :alt="restaurant.name"
            aspect="4/3"
        >
            <template #overlays>
                <!-- Rank badge (top-left) -->
                <div class="absolute left-3 top-3">
                    <div
                        class="flex h-9 min-w-[36px] items-center justify-center rounded-full bg-gradient-to-r px-3 text-sm font-bold shadow-lg ring-2 ring-white/50 transition-transform duration-200 group-hover:scale-110"
                        :class="[rankStyle.bg, rankStyle.text]"
                    >
                        <span v-if="rank === 1">🔥</span>
                        <span v-else class="tabular-nums">#{{ rankLabel }}</span>
                    </div>
                </div>

                <!-- ScoreChip (bottom-right) -->
                <div v-if="restaurant.popularity_score != null" class="absolute bottom-3 right-3">
                    <ScoreChip :total="restaurant.popularity_score" />
                </div>

                <!-- Heart/favorites button (top-right) -->
                <button
                    class="relative z-10 absolute -right-1.5 -top-1.5 flex h-11 w-11 items-center justify-center rounded-full bg-white/95 text-foreground shadow-md ring-2 ring-white/50 transition-all hover:bg-white hover:scale-110 group-hover:opacity-100 opacity-0"
                    :class="{ 'text-red-500 fill-red-500': saved, 'opacity-100': saved }"
                    :aria-label="ariaLabel"
                    @click.stop="() => toggle(restaurant)"
                >
                    <Heart class="h-4 w-4" :class="saved ? 'fill-current' : 'fill-none stroke-current'" />
                </button>
            </template>
        </CardGallery>

        <!-- Content section -->
        <div class="p-4 space-y-2">
            <!-- Name + award + address -->
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h3 class="text-base font-semibold text-foreground transition-colors group-hover:text-primary truncate">
                        <a :href="detailOrMapsUrl" :target="restaurant.id > 0 ? undefined : '_blank'" :rel="restaurant.id > 0 ? undefined : 'noopener'" class="after:absolute after:inset-0 after:z-0">
                            {{ restaurant.name }}
                        </a>
                    </h3>
                    <span v-if="restaurant.has_award" class="shrink-0 inline-flex items-center gap-0.5 rounded-full bg-amber-400/20 px-1.5 py-0.5 text-[10px] font-semibold text-amber-600">
                        ⭐ Award
                    </span>
                </div>
                <p v-if="restaurant.address || restaurant.city" class="truncate text-xs text-muted-foreground mt-0.5">
                    {{ restaurant.address ? restaurant.address : [restaurant.city, restaurant.state].filter(Boolean).join(', ') }}
                </p>
            </div>

            <!-- Rating + reviews + price + distance -->
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                <StarRating
                    v-if="displayRating"
                    :rating="displayRating.rating"
                    :source="displayRating.source"
                    :review-count="displayRating.count"
                    size="sm"
                />
                <span v-if="restaurant.price_range" class="text-sm font-semibold text-emerald-500 dark:text-emerald-400">
                    {{ restaurant.price_range }}
                </span>
                <span v-if="restaurant.distance != null" class="text-xs text-muted-foreground">
                    {{ Number(restaurant.distance).toFixed(1) }} km
                </span>
            </div>

            <!-- Description -->
            <p v-if="restaurant.description" class="line-clamp-1 sm:line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                {{ restaurant.description }}
            </p>

            <!-- Cuisine badges -->
            <div v-if="restaurant.cuisines.length > 0" class="flex flex-wrap gap-1">
                <Badge v-for="cuisine in restaurant.cuisines" :key="cuisine.id" variant="secondary" class="bg-primary/5 text-[11px] font-medium text-primary/70 hover:bg-primary/10">
                    {{ cuisine.name }}
                </Badge>
            </div>

            <!-- Action icon pills -->
            <div class="flex items-center gap-2 pt-0.5">
                <a
                    v-if="mapCoords"
                    :href="`https://www.google.com/maps/dir/?api=1&destination=${mapCoords.lat},${mapCoords.lng}`"
                    target="_blank"
                    rel="noopener"
                    class="relative z-10 inline-flex min-h-[44px] items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    title="Get directions"
                    @click.stop="trackDirections(restaurant.id)"
                >
                    <Navigation class="h-3.5 w-3.5" />
                    <span>Directions</span>
                </a>
                <button
                    v-if="restaurant.phone"
                    class="relative z-10 inline-flex min-h-[44px] items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    :title="`Call ${restaurant.phone}`"
                    @click.stop="callPhone(restaurant.phone, restaurant.id)"
                >
                    <Phone class="h-3.5 w-3.5" />
                    <span>Call</span>
                </button>
                <button
                    v-if="restaurant.website_url"
                    class="relative z-10 inline-flex min-h-[44px] items-center gap-1.5 rounded-full bg-muted/50 px-3 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                    title="Visit website"
                    @click.stop="openWebsite(restaurant.website_url, restaurant.id)"
                >
                    <Globe class="h-3.5 w-3.5" />
                    <span>Website</span>
                </button>
            </div>
        </div>
    </article>
</template>
