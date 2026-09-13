<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import StarRating from '@/Components/StarRating.vue';
import PriceLevel from '@/Components/PriceLevel.vue';
import ScoreBreakdown from '@/Components/ScoreBreakdown.vue';
import DetailMap from '@/Components/DetailMap.vue';
import SocialLinks from '@/Components/SocialLinks.vue';
import OpeningHours from '@/Components/OpeningHours.vue';
import RestaurantActionBar from '@/Components/RestaurantActionBar.vue';
import { getRestaurantGradient } from '@/composables/useRestaurantDisplay';
import { callPhone, openWebsite, trackDirections, trackPageview, trackMenuClick, directionsUrl, formatFullAddress, formatPhone } from '@/lib/restaurant';
import { Heart, ArrowLeft, MapPin, Navigation, Phone, Globe, UtensilsCrossed, Share2 } from '@lucide/vue';
import { getDisplayRating } from '@/composables/useRestaurantDisplay';
import { photoSrcset } from '@/lib/responsiveImage';
import { inAppHistory, isResultsUrl } from '@/lib/inAppHistory';
import { photoBadge } from '@/lib/scoreTier';
import { useFavorites } from '@/composables/useFavorites';
import { useSeo, generateRestaurantJsonLd } from '@/composables/useSeo';
import { useBaseUrl } from '@/composables/useBaseUrl';
import JsonLd from '@/Components/JsonLd.vue';
import SeoMeta from '@/Components/SeoMeta.vue';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    categorySlug: string | null;
    canonicalUrl?: string | null;
    isLivePreview?: boolean;
    restaurant: Restaurant;
}>();

const { isFavorited, toggle } = useFavorites();

const saved = computed(() => isFavorited(props.restaurant));
const ariaLabel = computed(() => (saved.value ? 'Saved' : 'Save restaurant'));

const photos = computed(() =>
    Array.from(
        new Set(
            [props.restaurant.photo_url, ...(props.restaurant.photos ?? [])].filter(
                Boolean,
            ) as string[],
        ),
    ),
);

const gradient = computed(() =>
    getRestaurantGradient(props.restaurant),
);

// SEO
const baseUrl = useBaseUrl()

const cuisineNames = computed(() =>
    props.restaurant.cuisines.map(c => c.name).join(', ')
)

const seoData = computed(() => {
    const title = `${props.restaurant.name} | ${cuisineNames.value} in ${props.restaurant.city || 'Your Area'}`
    const description = props.restaurant.description
        ? `${props.restaurant.description.substring(0, 160)}${props.restaurant.description.length > 160 ? '...' : ''}`
        : `Visit ${props.restaurant.name} for ${cuisineNames.value.toLowerCase()} cuisine in ${props.restaurant.city || 'your area'}. View ratings, reviews, photos, and more.`

    const restaurantUrl = props.canonicalUrl ?? `${baseUrl.value}/restaurants/${props.restaurant.slug}`;

    return useSeo({
        title,
        description,
        url: restaurantUrl,
        ...(photos.value[0] ? { image: photos.value[0] } : {}),
        type: 'restaurant',
        noindex: props.isLivePreview === true,
    })
})

const structuredData = computed(() => {
    const restaurantData = {
        name: props.restaurant.name,
        url: props.canonicalUrl ?? `${baseUrl.value}/restaurants/${props.restaurant.slug}`,
        address: props.restaurant.address,
        city: props.restaurant.city,
        state: props.restaurant.state,
        latitude: props.restaurant.lat,
        longitude: props.restaurant.lng,
        phone: props.restaurant.phone,
        google_rating: props.restaurant.google_rating,
        google_review_count: props.restaurant.google_review_count,
        cuisines: props.restaurant.cuisines,
        price_range: props.restaurant.price_range,
    }

    return generateRestaurantJsonLd(restaurantData)
})

onMounted(() => {
    trackPageview(props.restaurant.id);
})

// The photo band leads with the restaurant's first photo.
const heroPhoto = computed(() => photos.value[0] ?? null);
const heroSrcset = computed(() => photoSrcset(heroPhoto.value));
const photoBroken = ref(false);

const displayRating = computed(() => getDisplayRating(props.restaurant));
const badge = computed(() => photoBadge(props.restaurant));
const bandCuisines = computed(() => props.restaurant.cuisines.slice(0, 3).map((c) => c.name).join(', '));
const place = computed(() => [props.restaurant.city, props.restaurant.state].filter(Boolean).join(', '));
// Back: opened from a page on this site, go back to it the way the browser's
// back button does (results keep their filters and scroll). Opened from
// elsewhere, search for more of its cuisine.
const cuisineSlug = computed(() => props.restaurant.cuisines[0]?.slug ?? null);
const backHref = computed(() =>
    inAppHistory.openedByLink && inAppHistory.previousUrl
        ? inAppHistory.previousUrl
        : cuisineSlug.value ? `/search?cuisine=${cuisineSlug.value}` : '/search',
);
const backLabel = computed(() =>
    !inAppHistory.openedByLink || isResultsUrl(inAppHistory.previousUrl) ? 'Back to results' : 'Back',
);
function goBack(event: MouseEvent): void {
    if (!inAppHistory.openedByLink || event.metaKey || event.ctrlKey || event.shiftKey || event.button !== 0) return;
    event.preventDefault();
    window.history.back();
}

// Share: the phone's own share sheet where there is one, otherwise copy the link.
const shareLabel = ref('Share');
async function share(): Promise<void> {
    const url = props.canonicalUrl ?? window.location.href;
    if (typeof navigator.share === 'function') {
        try {
            await navigator.share({ title: props.restaurant.name, url });
        } catch {
            // Dismissed.
        }
        return;
    }
    try {
        await navigator.clipboard.writeText(url);
        shareLabel.value = 'Link copied';
        setTimeout(() => { shareLabel.value = 'Share'; }, 2000);
    } catch {
        shareLabel.value = 'Share';
    }
}

// Display is set per button: Directions, Call and Website live in the bottom
// bar on phones, so in this row they show from 768px up only.
const actionClass = 'min-h-11 items-center gap-2 rounded-full border border-border px-4 text-sm font-medium text-foreground transition-colors hover:bg-accent';

function handleMenuClick(): void {
    if (props.restaurant.menu_url) {
        trackMenuClick(props.restaurant.id);
        window.open(props.restaurant.menu_url, '_blank');
    }
}
</script>

<template>
    <AppLayout>
        <SeoMeta :seoData="seoData" />
        <link
            v-if="heroPhoto"
            rel="preload"
            as="image"
            :href="heroPhoto"
            fetchpriority="high"
        />

        <!-- Structured data — Inertia <Head> drops <script> tags, so inject via JsonLd -->
        <JsonLd :data="structuredData" />

        <!-- Photo band: the name, rating and essentials over the restaurant's photo -->
        <section class="relative h-[300px] w-full overflow-hidden sm:h-[360px] lg:h-[420px]" :class="gradient" data-testid="photo-band">
            <img
                v-if="heroPhoto && !photoBroken"
                :src="heroPhoto"
                :srcset="heroSrcset ?? undefined"
                sizes="100vw"
                :alt="restaurant.name"
                class="absolute inset-0 h-full w-full object-cover"
                fetchpriority="high"
                decoding="async"
                @error="photoBroken = true"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/35 to-black/10" />
            <div class="absolute inset-x-0 bottom-0">
                <div class="mx-auto max-w-7xl px-4 pb-6 sm:px-6 lg:px-8">
                    <a
                        :href="backHref"
                        class="mb-3 inline-flex min-h-11 items-center gap-1.5 rounded-full bg-black/40 px-4 text-sm font-medium text-white backdrop-blur-sm transition-colors hover:bg-black/55"
                        data-testid="back-link"
                        @click="goBack"
                    >
                        <ArrowLeft :size="16" />
                        {{ backLabel }}
                    </a>
                    <span
                        v-if="badge"
                        class="mb-2 block w-fit rounded-md bg-primary px-2 py-1 text-[13px] font-semibold leading-none text-primary-foreground"
                        data-testid="photo-badge"
                    >{{ badge }}</span>
                    <h1 class="font-heading text-3xl font-bold leading-tight text-white text-balance sm:text-4xl lg:text-5xl">{{ restaurant.name }}</h1>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-white/90">
                        <StarRating
                            v-if="displayRating"
                            :rating="displayRating.rating"
                            :source="displayRating.source"
                            :review-count="displayRating.count"
                            tone="inverse"
                        />
                        <span
                            v-else
                            class="rounded-full border border-white/40 px-2 py-0.5 text-xs font-medium text-white/90"
                        >Not yet rated</span>
                        <PriceLevel v-if="restaurant.price_range" :price="restaurant.price_range" tone="inverse" />
                        <span v-if="bandCuisines">{{ bandCuisines }}</span>
                        <span v-if="place" class="text-white/80">{{ place }}</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="mx-auto max-w-7xl px-4 pb-28 sm:px-6 md:pb-12 lg:px-8">
            <!-- Actions -->
            <div class="flex flex-wrap gap-2 border-b border-border py-4" data-testid="actions">
                <button
                    type="button"
                    :class="[actionClass, 'inline-flex', saved ? 'border-primary text-primary' : '']"
                    :aria-label="ariaLabel"
                    @click="() => toggle(restaurant)"
                >
                    <Heart class="h-4 w-4" :class="saved ? 'fill-current' : 'fill-none stroke-current'" />
                    {{ saved ? 'Saved' : 'Save' }}
                </button>
                <button type="button" :class="[actionClass, 'inline-flex']" data-testid="share" @click="share">
                    <Share2 class="h-4 w-4" />
                    {{ shareLabel }}
                </button>
                <a
                    v-if="restaurant.lat && restaurant.lng"
                    :href="directionsUrl(restaurant.lat, restaurant.lng)"
                    target="_blank"
                    rel="noopener"
                    :class="[actionClass, 'hidden md:inline-flex']"
                    @click="trackDirections(restaurant.id)"
                >
                    <Navigation class="h-4 w-4" />
                    Directions
                </a>
                <button
                    v-if="restaurant.phone"
                    type="button"
                    :class="[actionClass, 'hidden md:inline-flex']"
                    @click="() => callPhone(restaurant.phone!, restaurant.id)"
                >
                    <Phone class="h-4 w-4" />
                    Call
                </button>
                <button
                    v-if="restaurant.website_url"
                    type="button"
                    :class="[actionClass, 'hidden md:inline-flex']"
                    @click="() => openWebsite(restaurant.website_url!, restaurant.id)"
                >
                    <Globe class="h-4 w-4" />
                    Website
                </button>
                <button v-if="restaurant.menu_url" type="button" :class="[actionClass, 'inline-flex']" @click="handleMenuClick">
                    <UtensilsCrossed class="h-4 w-4" />
                    Menu
                </button>
            </div>

            <div class="grid gap-10 py-8 lg:grid-cols-[minmax(0,1fr)_340px]">
                <div class="min-w-0 space-y-10">
                    <section v-if="restaurant.description" aria-labelledby="about-heading">
                        <h2 id="about-heading" class="mb-3 text-xl font-semibold text-foreground">About</h2>
                        <p class="max-w-prose leading-relaxed text-muted-foreground">{{ restaurant.description }}</p>
                    </section>

                    <section v-if="restaurant.opening_hours" aria-labelledby="hours-heading">
                        <h2 id="hours-heading" class="mb-3 text-xl font-semibold text-foreground">Hours</h2>
                        <OpeningHours :hours="restaurant.opening_hours" />
                    </section>

                    <section v-if="restaurant.address || (restaurant.lat && restaurant.lng)" aria-labelledby="location-heading">
                        <h2 id="location-heading" class="mb-3 text-xl font-semibold text-foreground">Location</h2>
                        <p v-if="restaurant.address" class="mb-3 flex items-start gap-2 text-sm text-muted-foreground">
                            <MapPin :size="16" class="mt-0.5 shrink-0" />
                            {{ formatFullAddress(restaurant) }}
                        </p>
                        <DetailMap
                            v-if="restaurant.lat && restaurant.lng"
                            :lat="restaurant.lat"
                            :lng="restaurant.lng"
                            :name="restaurant.name"
                            :address="restaurant.address"
                        />
                    </section>

                    <section v-if="restaurant.score_breakdown" aria-labelledby="rank-heading">
                        <h2 id="rank-heading" class="mb-1 text-xl font-semibold text-foreground">Why it ranks here</h2>
                        <p class="mb-4 text-sm text-muted-foreground">What counted most toward this restaurant's place in the rankings.</p>
                        <ScoreBreakdown :breakdown="restaurant.score_breakdown" />
                    </section>

                    <section v-if="restaurant.social_links && restaurant.social_links.length > 0" aria-labelledby="social-heading">
                        <h2 id="social-heading" class="mb-3 text-xl font-semibold text-foreground">Find it online</h2>
                        <SocialLinks :links="restaurant.social_links" :restaurant-id="restaurant.id" />
                    </section>
                </div>

                <!-- Contact card: stays in view on desktop -->
                <aside class="hidden lg:block" data-testid="contact-card">
                    <div class="sticky top-24 space-y-4 rounded-xl border border-border bg-card p-5">
                        <button
                            v-if="restaurant.website_url"
                            type="button"
                            class="flex w-full items-center justify-between gap-3 text-left text-sm text-foreground hover:text-primary"
                            @click="() => openWebsite(restaurant.website_url!, restaurant.id)"
                        >
                            <span class="truncate">{{ restaurant.website_url.replace(/^https?:\/\//, '').replace(/\/$/, '') }}</span>
                            <Globe :size="18" class="shrink-0 text-muted-foreground" />
                        </button>
                        <button
                            v-if="restaurant.phone"
                            type="button"
                            class="flex w-full items-center justify-between gap-3 border-t border-border pt-4 text-left text-sm text-foreground hover:text-primary"
                            @click="() => callPhone(restaurant.phone!, restaurant.id)"
                        >
                            <span>{{ formatPhone(restaurant.phone) }}</span>
                            <Phone :size="18" class="shrink-0 text-muted-foreground" />
                        </button>
                        <a
                            v-if="restaurant.lat && restaurant.lng"
                            :href="directionsUrl(restaurant.lat, restaurant.lng)"
                            target="_blank"
                            rel="noopener"
                            class="flex w-full items-start justify-between gap-3 border-t border-border pt-4 text-sm text-foreground hover:text-primary"
                            @click="trackDirections(restaurant.id)"
                        >
                            <span>
                                <span class="block font-medium text-primary">Get directions</span>
                                <span v-if="restaurant.address" class="text-muted-foreground">{{ formatFullAddress(restaurant) }}</span>
                            </span>
                            <Navigation :size="18" class="mt-0.5 shrink-0 text-muted-foreground" />
                        </a>
                    </div>
                </aside>
            </div>
        </div>

        <RestaurantActionBar :restaurant="restaurant" />
    </AppLayout>
</template>
