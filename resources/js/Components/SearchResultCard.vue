<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import StarRating from '@/Components/StarRating.vue';
import ScoreChip from '@/Components/ScoreChip.vue';
import PriceLevel from '@/Components/PriceLevel.vue';
import { Heart, Navigation, Phone, Globe } from '@lucide/vue';
import { useFavorites } from '@/composables/useFavorites';
import { callPhone, openWebsite, trackDirections } from '@/lib/restaurant';
import { photoBadge } from '@/lib/scoreTier';
import type { Restaurant } from '@/types/restaurant';
import { getDetailUrl, getDisplayRating, getMapCoords, getRestaurantGradient } from '@/composables/useRestaurantDisplay';

// One search result, laid out like Yelp's: the rank is part of the name
// ("1. Austhentico") and the photo carries at most one solid badge. On phones
// a 96px thumbnail sits beside the details, with the actions on their own row.
const props = defineProps<{
    restaurant: Restaurant;
    rank: number;
    searchLat?: number | null;
    searchLng?: number | null;
}>();

const { isFavorited, toggle } = useFavorites();

// Tracks a photo_url that is present but failed to load, so a dead URL falls
// back to the placeholder instead of showing the browser's broken-image icon.
const photoBroken = ref(false);

const detailOrMapsUrl = computed(() => getDetailUrl(props.restaurant));

const displayRating = computed(() => getDisplayRating(props.restaurant));

const mapCoords = computed(() => getMapCoords(props.restaurant));

const saved = computed(() => isFavorited(props.restaurant));

const badge = computed(() => photoBadge(props.restaurant));

const cuisines = computed(() => props.restaurant.cuisines.slice(0, 2));

const place = computed(() => props.restaurant.address || [props.restaurant.city, props.restaurant.state].filter(Boolean).join(', '));

const reviewSnippet = computed(() => {
    if (!props.restaurant.description) return null;
    return props.restaurant.description.length > 120
        ? props.restaurant.description.slice(0, 120) + '…'
        : props.restaurant.description;
});

const gradient = computed(() => getRestaurantGradient(props.restaurant));

const actionClass = 'relative z-10 inline-flex min-h-12 flex-1 flex-col items-center justify-center gap-0.5 rounded-lg border border-border px-2 text-xs font-medium text-foreground transition-colors hover:bg-accent sm:min-h-9 sm:flex-none sm:flex-row sm:gap-1.5 sm:rounded-full sm:px-3.5 sm:text-sm';
</script>

<template>
    <article
        class="group relative grid grid-cols-[6rem_minmax(0,1fr)] gap-3 rounded-xl border bg-card p-3 transition-shadow hover:shadow-md sm:grid-cols-[11rem_minmax(0,1fr)] sm:grid-rows-[1fr_auto] sm:gap-0 sm:overflow-hidden sm:p-0"
    >
        <!-- Photo -->
        <div class="relative h-24 w-24 overflow-hidden rounded-lg sm:row-span-2 sm:h-full sm:min-h-44 sm:w-44 sm:rounded-none">
            <img
                v-if="restaurant.photo_url && !photoBroken"
                :src="restaurant.photo_url"
                :alt="restaurant.name"
                width="176"
                height="176"
                class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                loading="lazy"
                decoding="async"
                @error="photoBroken = true"
            />
            <div v-else class="flex h-full w-full flex-col items-center justify-center gap-1" :class="gradient">
                <span class="text-3xl text-white/60 sm:text-4xl" aria-hidden="true">🍽</span>
                <span class="hidden text-xs font-medium text-white/70 sm:block">Image coming soon</span>
            </div>

            <!-- At most one badge, solid and readable -->
            <span
                v-if="badge"
                data-testid="photo-badge"
                class="absolute left-1.5 top-1.5 rounded-md bg-primary px-1.5 py-1 text-xs font-semibold leading-none text-primary-foreground shadow-sm sm:left-2 sm:top-2 sm:px-2 sm:text-[13px]"
            >{{ badge }}</span>
        </div>

        <!-- Details -->
        <div class="min-w-0 space-y-1 sm:px-4 sm:pt-4">
            <h3 class="text-base font-semibold leading-snug text-foreground sm:pr-10 sm:text-[17px]">
                <a
                    :href="detailOrMapsUrl"
                    :target="restaurant.id > 0 ? undefined : '_blank'"
                    :rel="restaurant.id > 0 ? undefined : 'noopener'"
                    class="line-clamp-2 transition-colors after:absolute after:inset-0 after:z-0 group-hover:text-primary"
                >
                    <span class="tabular-nums" data-testid="rank">{{ rank }}.</span>
                    {{ restaurant.name }}
                </a>
            </h3>

            <StarRating
                v-if="displayRating"
                :rating="displayRating.rating"
                :source="displayRating.source"
                :review-count="displayRating.count"
                size="sm"
                compact-on-phone
            />
            <span
                v-else
                data-testid="not-yet-rated"
                class="inline-block rounded-full border border-border px-2 py-0.5 text-xs font-medium text-muted-foreground"
                title="No ratings yet — ranked on verified public data (independent sources, verified website and social profiles)"
            >
                Not yet rated
            </span>

            <p class="flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground" data-testid="meta">
                <template v-if="restaurant.price_range">
                    <PriceLevel :price="restaurant.price_range" />
                    <span v-if="cuisines.length || restaurant.distance != null" aria-hidden="true">·</span>
                </template>
                <template v-for="(cuisine, i) in cuisines" :key="cuisine.id">
                    <Link
                        :href="`/search?cuisine=${cuisine.slug}`"
                        class="relative z-10 hover:text-foreground hover:underline"
                    >{{ cuisine.name }}</Link>
                    <span v-if="i < cuisines.length - 1" aria-hidden="true">,</span>
                </template>
                <template v-if="restaurant.distance != null">
                    <span v-if="cuisines.length" aria-hidden="true">·</span>
                    <span class="tabular-nums">{{ Number(restaurant.distance).toFixed(1) }} mi</span>
                </template>
            </p>

            <p v-if="place" class="truncate text-sm text-muted-foreground">{{ place }}</p>

            <p v-if="reviewSnippet" class="hidden text-sm leading-relaxed text-muted-foreground sm:line-clamp-2">
                "{{ reviewSnippet }}"
                <Link
                    :href="detailOrMapsUrl"
                    class="relative z-10 whitespace-nowrap font-medium text-primary hover:underline"
                >
                    Read more
                </Link>
            </p>

            <ScoreChip
                v-if="restaurant.popularity_score != null"
                variant="link"
                :total="restaurant.popularity_score"
                :breakdown="restaurant.score_breakdown ?? null"
            />
        </div>

        <!-- Actions: their own row on phones -->
        <div class="col-span-2 flex items-center gap-2 sm:col-span-1 sm:col-start-2 sm:flex-wrap sm:px-4 sm:pb-4 sm:pt-2">
            <a
                v-if="mapCoords"
                :href="`https://www.google.com/maps/dir/?api=1&destination=${mapCoords.lat},${mapCoords.lng}`"
                target="_blank"
                rel="noopener"
                :class="actionClass"
                title="Get directions"
                @click.stop="trackDirections(restaurant.id)"
            >
                <Navigation class="h-4 w-4" aria-hidden="true" />
                <span>Directions</span>
            </a>
            <button
                v-if="restaurant.phone"
                type="button"
                :class="actionClass"
                :title="`Call ${restaurant.phone}`"
                @click.stop="callPhone(restaurant.phone, restaurant.id)"
            >
                <Phone class="h-4 w-4" aria-hidden="true" />
                <span>Call</span>
            </button>
            <button
                v-if="restaurant.website_url"
                type="button"
                :class="actionClass"
                title="Visit website"
                @click.stop="openWebsite(restaurant.website_url, restaurant.id)"
            >
                <Globe class="h-4 w-4" aria-hidden="true" />
                <span>Website</span>
            </button>

            <!-- Save: in the action row on phones, the card's top-right corner wider -->
            <button
                type="button"
                class="relative z-10 ml-auto flex h-12 w-12 shrink-0 items-center justify-center rounded-lg border border-border transition-colors hover:bg-accent sm:absolute sm:right-2 sm:top-2 sm:ml-0 sm:h-10 sm:w-10 sm:rounded-full sm:border-transparent"
                :class="{ 'text-primary': saved }"
                :aria-label="saved ? 'Saved' : 'Save restaurant'"
                @click.stop="() => toggle(restaurant)"
            >
                <Heart
                    class="h-5 w-5"
                    :class="saved ? 'fill-current' : 'fill-none stroke-current'"
                />
            </button>
        </div>
    </article>
</template>
