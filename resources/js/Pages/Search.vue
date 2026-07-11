<script setup lang="ts">
import { Head, router, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import SearchFilters from '@/Components/SearchFilters.vue';
import SearchResultCard from '@/Components/SearchResultCard.vue';
import SearchMap from '@/Components/SearchMap.vue';
import { Button } from '@/components/ui/button';
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
}>();

const isLoading = ref(false);

router.on('start', () => { isLoading.value = true; });
router.on('finish', () => { isLoading.value = false; });

const sortOptions = [
    { value: 'best_match', label: 'Best Match' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'rating', label: 'Rating' },
    { value: 'reviews', label: 'Reviews' },
    { value: 'price', label: 'Price (Low to High)' },
];

const currentSort = computed(() => (props.filters.sort as string) || 'best_match');
const currentPrice = computed(() => (props.filters.price_range as string) || '');

function updateSort(newSort: string) {
    router.get('/search', { ...props.filters, sort: newSort }, { preserveState: true, replace: true });
}

function handleFilterChange(changes: Record<string, string | undefined>) {
    router.get('/search', { ...props.filters, ...changes, page: undefined }, { preserveState: true, replace: true });
}

function goToPage(url: string | null) {
    if (url) {
        router.visit(url, { preserveState: true });
    }
}

function clearAll() {
    router.get('/search', {}, { replace: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Search Restaurants" />

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
                    <p class="text-sm text-muted-foreground">
                        <span v-if="restaurants.data.length > 0">
                            {{ (restaurants.current_page - 1) * restaurants.data.length + 1 }}–{{ restaurants.current_page * restaurants.data.length }} results
                        </span>
                        <span v-else>0 results</span>
                        <template v-if="cuisineName"> for {{ cuisineName }}</template>
                    </p>
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
                        :restaurants="restaurants.data"
                        :lat="filters.lat as string"
                        :lng="filters.lng as string"
                    />
                </div>
            </aside>
        </div>
    </AppLayout>
</template>
