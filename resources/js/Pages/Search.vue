<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchFilters from '@/Components/SearchFilters.vue';
import SearchResultCard from '@/Components/SearchResultCard.vue';
import SearchMap from '@/Components/SearchMap.vue';
import SeoMeta from '@/Components/SeoMeta.vue';
import JsonLd from '@/Components/JsonLd.vue';
import { Button } from '@/components/ui/button';
import { useSeo, generateItemListJsonLd } from '@/composables/useSeo';
import { useBaseUrl } from '@/composables/useBaseUrl';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    restaurants: {
        data: Restaurant[];
        current_page: number;
        last_page: number;
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
    enriching: boolean;
}>();

const isLoading = ref(false);
const dismissedLocationBanner = ref(localStorage.getItem('dismissedLocationBanner') === '1');

function dismissLocationBanner() {
    dismissedLocationBanner.value = true;
    localStorage.setItem('dismissedLocationBanner', '1');
}

router.on('start', () => { isLoading.value = true; });
router.on('finish', () => { isLoading.value = false; });

const sortOptions = [
    { value: 'best_match', label: 'Best Match' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'rating', label: 'Rating' },
    { value: 'reviews', label: 'Reviews' },
    { value: 'price', label: 'Price (Low to High)' },
    { value: 'social_presence', label: 'Social Presence' },
    { value: 'website_traffic', label: 'Website Traffic' },
];

const currentSort = computed(() => (props.filters.sort as string) || 'best_match');

function updateSort(newSort: string) {
    router.get('/search', { ...props.filters, sort: newSort }, { preserveState: true, replace: true });
}

function handleFilterChange(changes: Record<string, string | undefined>) {
    const merged: Record<string, string> = {};
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

const enrichedRestaurants = ref<Restaurant[]>([])
const enrichmentRetries = ref(0)
const enrichmentMaxRetries = 8
let enrichmentTimer: ReturnType<typeof setInterval> | null = null

if (props.enriching && props.restaurants.data.length === 0) {
    onMounted(() => {
        enrichmentTimer = setInterval(async () => {
            enrichmentRetries.value++
            if (enrichmentRetries.value > enrichmentMaxRetries) {
                if (enrichmentTimer) clearInterval(enrichmentTimer)
                return
            }

            try {
                const params = new URLSearchParams()
                if (props.filters.cuisine) params.set('cuisine', String(props.filters.cuisine))
                if (props.filters.category) params.set('category', String(props.filters.category))
                if (props.filters.lat) params.set('lat', String(props.filters.lat))
                if (props.filters.lng) params.set('lng', String(props.filters.lng))
                if (props.filters.distance) params.set('distance', String(props.filters.distance))
                if (props.filters.sort) params.set('sort', String(props.filters.sort))

                const res = await fetch(`/api/restaurants?${params}`)
                const data = await res.json()

                if (data?.data?.length > 0) {
                    enrichedRestaurants.value = data.data
                    if (enrichmentTimer) clearInterval(enrichmentTimer)
                }
            } catch {
                // Network error, will retry on next interval
            }
        }, 4000)
    })

    onUnmounted(() => {
        if (enrichmentTimer) clearInterval(enrichmentTimer)
    })
}

function retrySearch() {
    router.reload()
}

const displayRestaurants = computed(() =>
    enrichedRestaurants.value.length > 0 ? enrichedRestaurants.value : props.restaurants.data
)

// SEO
const baseUrl = useBaseUrl();

const seoData = computed(() => {
    const cuisine = props.cuisineName || (typeof props.filters.cuisine === 'string' ? props.filters.cuisine : null);
    const title = cuisine
        ? `Best ${cuisine} Near You | iPop360`
        : 'Search Restaurants Near You | iPop360';
    const description = cuisine
        ? `Search ${cuisine.toLowerCase()} restaurants by cuisine, rating, and price. Find the most popular dining spots near you with iPop360's smart rankings.`
        : 'Search for restaurants by cuisine, rating, and price. Find the most popular dining spots near you with iPop360\'s smart rankings.';

    return useSeo({
        title,
        description,
        type: 'website',
    });
});

const structuredData = computed(() => {
    if (displayRestaurants.value.length === 0) return null;

    const items = displayRestaurants.value
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
            <aside class="hidden w-64 shrink-0 lg:block">
                <div class="sticky top-24">
                    <SearchFilters
                        :filters="filters"
                        :filterOptions="filterOptions"
                        @update="handleFilterChange"
                        @clear="clearAll"
                    />
                </div>
            </aside>

            <!-- Center: results -->
            <main class="min-w-0 flex-1">
                <!-- Sort bar -->
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-baseline gap-2">
                        <h1 class="text-lg font-semibold text-foreground">
                            {{ cuisineName || 'All Restaurants' }}
                        </h1>
                        <p class="text-sm text-muted-foreground">
                            <span v-if="displayRestaurants.length > 0">
                                {{ (restaurants.current_page - 1) * displayRestaurants.length + 1 }}–{{ restaurants.current_page * displayRestaurants.length }} results
                            </span>
                            <span v-else>0 results</span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <label for="search-sort" class="text-sm text-muted-foreground">Sort:</label>
                        <select
                            id="search-sort"
                            :value="currentSort"
                            @change="updateSort(($event.target as HTMLSelectElement).value)"
                            class="rounded-md border border-input bg-background px-3 py-1.5 text-sm ring-offset-background focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        >
                            <option v-for="opt in sortOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                    </div>
                </div>

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
                    v-if="!dismissedLocationBanner && !hasCoords && filters.distance"
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

                <!-- Enriching state (first-time search, job running in background) -->
                <div v-else-if="enriching && displayRestaurants.length === 0 && enrichmentRetries < enrichmentMaxRetries" class="space-y-6">
                    <div class="rounded-xl border bg-card p-8 text-center">
                        <div class="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                        <p class="text-lg font-medium text-foreground">Searching nearby restaurants...</p>
                        <p class="mt-1 text-sm text-muted-foreground">Pulling results from multiple sources for your first search in this area.</p>
                    </div>
                    <div v-for="i in 3" :key="'skel-' + i" class="flex animate-pulse rounded-xl border bg-card">
                        <div class="h-44 w-44 shrink-0 rounded-l-xl bg-muted" />
                        <div class="flex-1 space-y-3 p-5">
                            <div class="h-5 w-3/4 rounded bg-muted" />
                            <div class="h-4 w-1/2 rounded bg-muted" />
                            <div class="h-4 w-1/3 rounded bg-muted" />
                            <div class="h-4 w-full rounded bg-muted" />
                        </div>
                    </div>
                </div>

                <!-- Enrichment timeout -->
                <div v-else-if="enriching && displayRestaurants.length === 0" class="rounded-xl border bg-card p-12 text-center">
                    <p class="text-lg font-medium text-foreground">Taking longer than expected...</p>
                    <p class="mt-2 text-sm text-muted-foreground">Results should appear soon. Try refreshing or adjust your filters.</p>
                    <div class="mt-4 flex justify-center gap-3">
                        <Button variant="outline" @click="retrySearch">Refresh</Button>
                        <Button variant="outline" @click="clearAll">Clear filters</Button>
                    </div>
                </div>

                <!-- Empty state -->
                <div v-else-if="displayRestaurants.length === 0" class="rounded-xl border bg-card p-12 text-center">
                    <p class="text-lg text-muted-foreground">No restaurants found.</p>
                    <p class="mt-2 text-sm text-muted-foreground">Try adjusting your filters or search for a different cuisine.</p>
                    <Button variant="outline" class="mt-4" @click="clearAll">Clear all filters</Button>
                </div>

                <!-- Results list -->
                <div v-else class="space-y-4">
                    <SearchResultCard
                        v-for="(restaurant, index) in displayRestaurants"
                        :key="restaurant.id"
                        :restaurant="restaurant"
                        :rank="(restaurants.current_page - 1) * 20 + index + 1"
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
            </main>

            <!-- Right column: map -->
            <aside class="hidden w-96 shrink-0 xl:block">
                <div class="sticky top-24">
                    <SearchMap
                        :restaurants="displayRestaurants"
                        :lat="filters.lat as string"
                        :lng="filters.lng as string"
                    />
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
