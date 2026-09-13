<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchFilters from '@/Components/SearchFilters.vue';
import SearchResultCard from '@/Components/SearchResultCard.vue';
import SearchMap from '@/Components/SearchMap.vue';
import SeoMeta from '@/Components/SeoMeta.vue';
import JsonLd from '@/Components/JsonLd.vue';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { SlidersHorizontal, X, Map, List } from '@lucide/vue';
import { useSeo, generateItemListJsonLd } from '@/composables/useSeo';
import { useBaseUrl } from '@/composables/useBaseUrl';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    restaurants: {
        data: Restaurant[];
        current_page: number;
        last_page: number;
        per_page?: number;
        total?: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Record<string, string | string[] | undefined>;
    cuisineName: string | null;
    categorySlug: string | null;
    filterOptions: {
        categories: Array<{ id: number; name: string; slug: string; restaurants_count: number }>;
        cuisines: Array<{ id: number; name: string; slug: string; category_id: number }>;
        priceOptions: string[];
        distanceOptions: number[];
    };
    hasCoords: boolean;
}>();

const isLoading = ref(false);
const filtersOpen = ref(false);
const showMap = ref(false);
const dismissedLocationBanner = ref(
    typeof window !== 'undefined' && localStorage.getItem('dismissedLocationBanner') === '1'
);

function dismissLocationBanner() {
    dismissedLocationBanner.value = true;
    if (typeof window !== 'undefined') {
        localStorage.setItem('dismissedLocationBanner', '1');
    }
}

// Skeleton only while this page re-fetches its own results (filter, sort,
// pagination). On a navigation away the results must stay put: swapping them
// for skeletons reflows the outgoing page, and Chrome's scroll anchoring then
// moves the scroll position Inertia saved, so "Back to results" lands
// somewhere else.
router.on('start', (event) => {
    isLoading.value = event.detail.visit.url.pathname === '/search';
});
router.on('finish', () => { isLoading.value = false; });

const serpapiExhausted = computed(() => usePage().props.serpapi_exhausted);
const sortOptions = computed(() => [
    { value: 'best_match', label: 'Best Match' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'rating', label: serpapiExhausted.value ? 'Ratings temporarily unavailable' : 'Rating' },
    { value: 'reviews', label: 'Reviews' },
    { value: 'price', label: 'Price (Low to High)' },
    { value: 'social_presence', label: 'Social Presence' },
    { value: 'website_traffic', label: 'Website Traffic' },
]);

const currentSort = computed(() => (props.filters["sort"] as string) || 'best_match');

function updateSort(newSort: string) {
    router.get('/search', { ...props.filters, sort: newSort }, { preserveState: true, replace: true });
}

function handleFilterChange(changes: Record<string, string | string[] | undefined>) {
    const merged: Record<string, string | string[]> = {};
    for (const [key, value] of Object.entries({ ...props.filters, ...changes, page: undefined })) {
        if (value !== undefined && value !== null) {
            merged[key] = value;
        }
    }
    router.get('/search', merged, { preserveState: true, replace: true });
}

function goToPage(url: string | null) {
    if (url) {
        router.visit(url, { preserveState: true });
    }
}

function clearAll() {
    router.get('/search', {}, { replace: true });
}

// "21–40 of 143 results". The range comes from the page size, not the length
// of this page, so a short last page reads right.
const perPage = computed(() => props.restaurants.per_page ?? 20);
const rangeText = computed(() => {
    const count = props.restaurants.data.length;
    if (count === 0) return '0 results';
    const from = (props.restaurants.current_page - 1) * perPage.value + 1;
    const to = from + count - 1;
    const total = props.restaurants.total;
    return total != null ? `${from}–${to} of ${total.toLocaleString()} results` : `${from}–${to} results`;
});

// Phone filter chips: each opens the Filters sheet.
const selectedPrices = computed<string[]>(() => {
    const value = props.filters['price_range'];
    if (Array.isArray(value)) return value;
    return typeof value === 'string' && value !== '' ? [value] : [];
});
const currentDistance = computed(() => (props.filters['distance'] as string | undefined) || '25');
const distanceLabel = computed(() => (Number(currentDistance.value) >= 50 ? 'Within 50+ mi' : `Within ${currentDistance.value} mi`));
const activeFilterCount = computed(() =>
    (selectedPrices.value.length ? 1 : 0)
    + (currentDistance.value !== '25' ? 1 : 0)
    + (props.filters['category'] || props.filters['cuisine'] ? 1 : 0),
);

const chipClass = 'inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border px-4 text-sm font-medium transition-colors';

// SEO
const baseUrl = useBaseUrl();

const seoData = computed(() => {
    const cuisine = props.cuisineName || (typeof props.filters["cuisine"] === 'string' ? props.filters["cuisine"] : null);
    const title = cuisine
        ? `Best ${cuisine} Near You`
        : 'Search Restaurants Near You';
    const description = cuisine
        ? (serpapiExhausted.value
            ? `Search ${cuisine.toLowerCase()} restaurants by cuisine and price. Find the most popular dining spots near you with iPop360's smart rankings.`
            : `Search ${cuisine.toLowerCase()} restaurants by cuisine, rating, and price. Find the most popular dining spots near you with iPop360's smart rankings.`)
        : (serpapiExhausted.value
            ? 'Search for restaurants by cuisine and price. Find the most popular dining spots near you with iPop360\'s smart rankings.'
            : 'Search for restaurants by cuisine, rating, and price. Find the most popular dining spots near you with iPop360\'s smart rankings.');

    return useSeo({
        title,
        description,
        url: `${baseUrl.value}${usePage().url}`,
        type: 'website',
    });
});

const structuredData = computed(() => {
    if (props.restaurants.data.length === 0) return null;

    const items = props.restaurants.data
        .filter((_, index) => index < 10)
        .map((restaurant, index) => ({
            name: restaurant.name,
            url: `${baseUrl.value}/restaurants/${restaurant.slug}`,
            position: index + 1,
        }));

    return generateItemListJsonLd(items);
});
</script>

<template>
    <AppLayout>
        <SeoMeta :seoData="seoData" />
        <JsonLd :data="structuredData" />

        <div class="mx-auto flex max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:px-8">
            <!-- Left sidebar: filters -->
            <aside aria-label="Filters" class="hidden w-64 shrink-0 lg:block">
                <div class="sticky top-24">
                    <SearchFilters
                        :filters="filters"
                        :filterOptions="filterOptions"
                        @update="handleFilterChange"
                        @clear="clearAll"
                    />
                </div>
            </aside>

            <!-- Center: results (the layout already has the page's <main>) -->
            <div class="min-w-0 flex-1">
                <!-- Title, result count and sort -->
                <div class="mb-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <h1 class="text-xl font-semibold text-foreground sm:text-2xl">
                            {{ cuisineName || 'All restaurants' }}
                        </h1>
                        <p class="text-sm text-muted-foreground" data-testid="results-range">{{ rangeText }}</p>
                    </div>
                    <div class="flex min-w-0 items-center gap-2">
                        <label for="search-sort" class="shrink-0 text-sm text-muted-foreground">Sort by</label>
                        <select
                            id="search-sort"
                            :value="currentSort"
                            @change="updateSort(($event.target as HTMLSelectElement).value)"
                            class="min-h-11 min-w-0 max-w-full rounded-md border border-input bg-background px-3 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 sm:min-h-9"
                        >
                            <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Phone and tablet: a row of filter chips that scrolls sideways -->
                <div
                    class="-mx-4 mb-4 flex gap-2 overflow-x-auto overscroll-x-contain px-4 pb-1 [scrollbar-width:none] sm:-mx-6 sm:px-6 lg:mx-0 lg:px-0 xl:hidden [&::-webkit-scrollbar]:hidden"
                    data-testid="filter-chips"
                >
                    <button
                        type="button"
                        :class="[chipClass, 'border-border bg-background text-foreground hover:bg-accent lg:hidden']"
                        data-testid="mobile-filter-toggle"
                        @click="filtersOpen = true"
                    >
                        <SlidersHorizontal :size="16" aria-hidden="true" />
                        Filters<span v-if="activeFilterCount" class="tabular-nums">&nbsp;· {{ activeFilterCount }}</span>
                    </button>
                    <button
                        type="button"
                        :class="[chipClass, 'lg:hidden', selectedPrices.length ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-background text-foreground hover:bg-accent']"
                        data-testid="price-chip"
                        @click="filtersOpen = true"
                    >
                        {{ selectedPrices.length ? selectedPrices.join(', ') : 'Price' }}
                    </button>
                    <button
                        type="button"
                        :class="[chipClass, 'border-border bg-background text-foreground hover:bg-accent lg:hidden']"
                        @click="filtersOpen = true"
                    >
                        {{ distanceLabel }}
                    </button>
                    <button
                        type="button"
                        :class="[chipClass, 'border-border bg-background text-foreground hover:bg-accent xl:hidden']"
                        data-testid="mobile-map-toggle"
                        @click="showMap = !showMap"
                    >
                        <Map v-if="!showMap" :size="16" aria-hidden="true" />
                        <List v-else :size="16" aria-hidden="true" />
                        {{ showMap ? 'List' : 'Map' }}
                    </button>
                </div>

                <Sheet v-model:open="filtersOpen">
                    <SheetContent side="bottom" class="max-h-[85vh] p-0 pb-[env(safe-area-inset-bottom)]" :show-close-button="false">
                        <SheetTitle class="sr-only">Filters</SheetTitle>
                        <SheetDescription class="sr-only">Filter restaurants by price, category, and distance</SheetDescription>
                        <div class="flex items-center justify-between border-b border-border px-4 py-3">
                            <div class="mx-auto h-1 w-10 rounded-full bg-muted-foreground/30" />
                            <button
                                class="flex h-11 w-11 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground transition-colors"
                                @click="filtersOpen = false"
                                aria-label="Close"
                                data-testid="filter-close"
                            >
                                <X :size="18" />
                            </button>
                        </div>
                        <div class="overflow-y-auto overscroll-contain px-4 py-4">
                            <SearchFilters
                                :filters="filters"
                                :filterOptions="filterOptions"
                                @update="handleFilterChange"
                                @clear="clearAll"
                            />
                        </div>
                    </SheetContent>
                </Sheet>

                <!-- Mobile map view -->
                <div v-if="showMap" class="xl:hidden" data-testid="mobile-map">
                    <SearchMap
                        :restaurants="restaurants.data"
                        :lat="filters['lat'] as string"
                        :lng="filters['lng'] as string"
                    />
                </div>

                <template v-else>
                    <!-- Skeleton loader -->
                    <div v-if="isLoading" class="space-y-6">
                    <div v-for="i in 5" :key="'skel-' + i" class="flex animate-pulse rounded-xl border bg-card">
                        <div class="h-44 w-44 shrink-0 rounded-l-xl bg-muted" />
                        <div class="flex-1 space-y-3 p-5">
                            <div class="h-5 w-3/4 rounded bg-muted" />
                            <div class="h-4 w-1/2 rounded bg-muted" />
                            <div class="h-4 w-1/3 rounded bg-muted" />
                            <div class="h-4 w-full rounded bg-muted" />
                        </div>
                    </div>
                </div>

                <!-- Location needed banner -->
                <div
                    v-if="!dismissedLocationBanner && !hasCoords && filters['distance']"
                    class="mb-4 flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200"
                >
                    <span>Enable location sharing to filter results by distance.</span>
                    <button
                        class="ml-3 shrink-0 font-semibold text-amber-800 hover:text-amber-600 dark:text-amber-200 dark:hover:text-amber-400"
                        @click="dismissLocationBanner"
                    >
                        Dismiss
                    </button>
                </div>

                <!-- Empty state -->
                <div v-else-if="restaurants.data.length === 0" class="rounded-xl border bg-card p-12 text-center">
                    <p class="text-lg text-muted-foreground">No restaurants found.</p>
                    <p class="mt-2 text-sm text-muted-foreground">Try adjusting your filters or search for a different cuisine.</p>
                    <Button variant="outline" class="mt-4" @click="clearAll">Clear all filters</Button>
                </div>

                <!-- Results list -->
                <div v-else class="space-y-4">
                    <SearchResultCard
                        v-for="(restaurant, index) in restaurants.data"
                        :key="restaurant.id"
                        :restaurant="restaurant"
                        :rank="(restaurants.current_page - 1) * perPage + index + 1"
                    />

                    <!-- Pagination -->
                    <div v-if="restaurants.last_page > 1" class="flex items-center justify-center gap-4 pt-4">
                        <Button
                            v-if="restaurants.prev_page_url"
                            variant="outline"
                            size="sm"
                            @click="goToPage(restaurants.prev_page_url)"
                        >
                            Previous
                        </Button>
                        <span class="text-sm text-muted-foreground">
                            Page {{ restaurants.current_page }} of {{ restaurants.last_page }}
                        </span>
                        <Button
                            v-if="restaurants.next_page_url"
                            variant="outline"
                            size="sm"
                            @click="goToPage(restaurants.next_page_url)"
                        >
                            Next
                        </Button>
                    </div>
                </div>
                </template>
            </div>

            <!-- Right column: map -->
            <aside aria-label="Map" class="hidden w-96 shrink-0 xl:block">
                <div class="sticky top-24">
                    <SearchMap
                        :restaurants="restaurants.data"
                        :lat="filters['lat'] as string"
                        :lng="filters['lng'] as string"
                    />
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
