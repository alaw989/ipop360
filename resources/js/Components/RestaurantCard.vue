<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import StarRating from '@/Components/StarRating.vue';
import CardGallery from '@/Components/CardGallery.vue';
import ScoreChip from '@/Components/ScoreChip.vue';
import { computed } from 'vue';
import type { Restaurant } from '@/types/restaurant';
import { callPhone, openWebsite, trackDirections } from '@/lib/restaurant';
import { Phone, Globe, Navigation, Heart, ArrowUp, ArrowDown, Minus } from '@lucide/vue';
import { useFavorites } from '@/composables/useFavorites';
import { useCompare } from '@/composables/useCompare';
import { getDetailUrl, getRankStyle, getRestaurantPhotos, getRestaurantGradient, getDisplayRating, getMapCoords } from '@/composables/useRestaurantDisplay';

const props = defineProps<{
    restaurant: Restaurant;
    rank: number;
    searchLat?: number | null;
    searchLng?: number | null;
    cuisine?: string;
    stagger?: boolean;
}>();

const { isFavorited, toggle } = useFavorites();

const detailOrMapsUrl = computed(() => getDetailUrl(props.restaurant));

const rankStyle = computed(() => getRankStyle(props.rank));

const photos = computed(() => getRestaurantPhotos(props.restaurant));

const gradient = computed(() => getRestaurantGradient(props.restaurant));

const displayRating = computed(() => getDisplayRating(props.restaurant));

const mapCoords = computed(() => getMapCoords(props.restaurant));

const saved = computed(() => isFavorited(props.restaurant));

const { isInCompare, toggleCompare } = useCompare();
const inCompare = computed(() => isInCompare(props.restaurant));

const ariaLabel = computed(() => (saved.value ? 'Saved' : 'Save restaurant'));

const rankChangeColor = computed(() => {
    const c = props.restaurant.rank_change;
    if (c == null) return '';
    if (c > 0) return 'text-green-600 dark:text-green-400';
    if (c < 0) return 'text-red-600 dark:text-red-400';
    return 'text-muted-foreground';
});

const rankChangeTitle = computed(() => {
    const c = props.restaurant.rank_change;
    if (c == null) return '';
    if (c > 0) return `Up ${c} spots`;
    if (c < 0) return `Down ${Math.abs(c)} spots`;
    return 'Steady';
});
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
                <div class="absolute left-3 top-3 flex items-start gap-1">
                    <div
                        class="flex h-9 min-w-[36px] items-center justify-center rounded-full bg-gradient-to-r px-3 text-sm font-bold shadow-lg ring-2 ring-white/50 transition-transform duration-200 group-hover:scale-110"
                        :class="[rankStyle.bg, rankStyle.text]"
                    >
                        <span v-if="rank === 1">🔥</span>
                        <span v-else class="tabular-nums">#{{ rank }}</span>
                    </div>
                    <div
                        v-if="restaurant.rank_change != null"
                        class="mt-1 flex h-5 w-5 items-center justify-center rounded-full bg-background/80 text-[10px] font-bold shadow-sm ring-1 ring-border backdrop-blur-sm"
                        :class="rankChangeColor"
                        :title="rankChangeTitle"
                    >
                        <ArrowUp v-if="restaurant.rank_change > 0" class="h-3 w-3" />
                        <ArrowDown v-else-if="restaurant.rank_change < 0" class="h-3 w-3" />
                        <Minus v-else class="h-3 w-3" />
                    </div>
                </div>

                <!-- ScoreChip (bottom-right) -->
                <div v-if="restaurant.popularity_score != null" class="absolute bottom-3 right-3">
                    <ScoreChip :total="restaurant.popularity_score" :breakdown="restaurant.score_breakdown ?? null" />
                </div>

                <!-- Compare button (bottom-left) -->
                <div class="absolute bottom-3 left-3 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button
                        class="flex h-7 w-7 items-center justify-center rounded-full bg-background/80 text-[10px] font-bold shadow-sm ring-1 ring-border backdrop-blur-sm hover:bg-background transition-colors"
                        :class="inCompare ? 'text-primary ring-primary/50' : 'text-muted-foreground'"
                        :title="inCompare ? 'Remove from comparison' : 'Add to comparison'"
                        @click.stop="toggleCompare(props.restaurant)"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5">
                            <rect x="2" y="3" width="6" height="18" rx="1" />
                            <rect x="9" y="7" width="6" height="14" rx="1" />
                            <rect x="16" y="5" width="6" height="16" rx="1" />
                        </svg>
                    </button>
                </div>

                <!-- Heart/favorites button (top-right) -->
                <button
                    class="relative z-10 absolute -right-1.5 -top-1.5 flex h-11 w-11 items-center justify-center rounded-full bg-white/95 text-foreground shadow-md ring-2 ring-white/50 transition-all hover:bg-white hover:scale-110 group-hover:opacity-0"
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
