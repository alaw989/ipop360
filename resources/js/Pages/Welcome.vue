<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, computed, onMounted, onUnmounted, watch, defineAsyncComponent } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { X } from '@lucide/vue'
import JsonLd from '@/Components/JsonLd.vue'
import HeroBanner from '@/Components/HeroBanner.vue'
import StickySearchBar from '@/Components/StickySearchBar.vue'
import CategoryGrid from '@/Components/CategoryGrid.vue'
import PopularCuisines from '@/Components/PopularCuisines.vue'
import PopularRestaurants from '@/Components/PopularRestaurants.vue'
// Lazy-load the results tree (ResultsGrid + RestaurantCard + CardGallery + …) so
// it isn't on the idle homepage entry chunk — it renders only in the results
// phase (spec-061 bundle diet).
const ResultsGrid = defineAsyncComponent(() => import('@/Components/ResultsGrid.vue'))

import { useSeo, generateWebSiteJsonLd, generateOrganizationJsonLd } from '@/composables/useSeo'
import { useRestaurantSearch } from '@/composables/useRestaurantSearch'
import { useGeolocation } from '@/composables/useGeolocation'
import { usePersistedLocation } from '@/composables/usePersistedLocation'
import { useBaseUrl } from '@/composables/useBaseUrl'
import SeoMeta from '@/Components/SeoMeta.vue'
import '../../css/transitions.css' // homepage-only transition choreography (spec-062)

type Phase = 'idle' | 'searching' | 'results' | 'empty' | 'error'

interface Cuisine {
    id: number
    name: string
    slug: string
    icon: string | null
}

interface Category {
    id: number
    name: string
    slug: string
    icon: string | null
    cuisines: Cuisine[]
}

interface Location {
    city: string | null
    state: string | null
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
    cuisines: Array<{ id: number; name: string; slug: string }>
}

interface HomepageData {
    categories: Category[]
    popularCuisines: Array<{
        id: number
        name: string
        slug: string
        icon: string | null
        restaurants_count: number
    }>
    popularRestaurants: Restaurant[]
    location: Location | null
}

const props = defineProps<{
    categories: Category[]
    popularCuisines: Array<{
        id: number
        name: string
        slug: string
        icon: string | null
        restaurants_count: number
    }>
    popularRestaurants: Restaurant[]
    location: Location | null
    fallbackCoords: { lat: number; lng: number } | null
}>()

// Phase machine
const phase = ref<Phase>('idle')
function setPhase(newPhase: Phase) {
    phase.value = newPhase
}
function getPhase(): Phase {
    return phase.value
}
const isResultsPhase = computed(() => phase.value !== 'idle')

// Cuisine selection state
const selectedCategory = ref('')
const selectedCuisine = ref<string | undefined>()
const selectedLabel = ref<string | null>(null)

// Sort state
const sort = ref<string>('best_match')
const sortOptions = [
    { value: 'best_match', label: 'Best Match' },
    { value: 'nearest', label: 'Nearest' },
    { value: 'rating', label: 'Rating' },
    { value: 'reviews', label: 'Reviews' },
    { value: 'price', label: 'Price' },
]

// Persisted location (city/state/coords from localStorage)
const { location: persistedLocation, lat, lng, persistLocation, restore: restorePersistedLocation } = usePersistedLocation(props.location, props.fallbackCoords)

// Geolocation (GPS + reverse geocode)
const { detectingLocation, geolocationError, detectLocation } = useGeolocation(persistLocation)

// Restaurant search (search/resort/loadMore)
const {
    restaurants,
    shouldStagger,
    isResorting,
    nextPageUrl,
    searchError,
    loadMoreError,
    search,
    resort,
    loadMore,
    resetState,
} = useRestaurantSearch(setPhase, getPhase)

// Result count for display
const resultCount = computed(() => restaurants.value.length)

// SEO
const baseUrl = useBaseUrl()

const seoData = computed(() => {
    return useSeo({
        title: 'Find Popular Restaurants Near You | iPop360',
        description: 'Discover top-rated restaurants near you with iPop360. Real reviews, accurate ratings, and smart rankings help you find the best dining options in your area.',
        url: `${baseUrl.value}/`,
        type: 'website',
    })
})

const structuredData = computed(() => {
    const webSite = generateWebSiteJsonLd(`${baseUrl.value}/`, 'iPop360')
    const organization = generateOrganizationJsonLd(`${baseUrl.value}/`, 'iPop360')
    return [webSite, organization]
})

// Reactive homepage data — initialised from server props, then refetched when
// the user changes city via LocationPicker. On mount, restorePersistedLocation
// may pull a saved city from localStorage that differs from the server-rendered
// props — the watcher below catches that change and fetches the correct data.
const categories = ref<Category[]>(props.categories)
const popularCuisines = ref<HomepageData['popularCuisines']>(props.popularCuisines)
const popularRestaurants = ref<HomepageData['popularRestaurants']>(props.popularRestaurants)
const dataLoading = ref(false)

// Tracks the actual location scope of the data shown (may differ from the
// selected city when no restaurants exist for it and fallback kicks in).
const effectiveLocation = ref<Location | null>(props.location)

onMounted(() => {
    restorePersistedLocation()
})

// Abort controller for in-flight homepage-data fetches.
const homepageAbortController = ref<AbortController | null>(null)

function fetchHomepageData(city: string | null, state: string | null) {
    homepageAbortController.value?.abort()
    const controller = new AbortController()
    homepageAbortController.value = controller

    dataLoading.value = true

    const params = new URLSearchParams()
    if (city) params.set('city', city)
    if (state) params.set('state', state)

    fetch(`/api/homepage-data?${params}`, { signal: controller.signal })
        .then(res => {
            if (!res.ok) return
            return res.json() as Promise<HomepageData>
        })
        .then(data => {
            if (!data) return
            categories.value = data.categories
            popularCuisines.value = data.popularCuisines
            popularRestaurants.value = data.popularRestaurants
            effectiveLocation.value = data.location
        })
        .catch(err => {
            if (err instanceof DOMException && err.name === 'AbortError') return
        })
        .finally(() => {
            dataLoading.value = false
        })
}

watch(
    () => [persistedLocation.value?.city, persistedLocation.value?.state] as const,
    ([newCity, newState]) => {
        dataLoading.value = true
        fetchHomepageData(newCity, newState)
    },
)

onUnmounted(() => {
    homepageAbortController.value?.abort()
})

// Event handlers from child components
function onCuisineSelect(payload: { category: string; cuisine?: string; label: string }) {
    selectedCategory.value = payload.category
    selectedCuisine.value = payload.cuisine
    selectedLabel.value = payload.label
}

function onLocationUpdate(newLocation: Location) {
    persistedLocation.value = newLocation
}

function onCoords(lt: number, lg: number) {
    lat.value = lt
    lng.value = lg
    persistLocation(persistedLocation.value.city, persistedLocation.value.state, lt, lg)
}

function onSearch() {
    router.get('/search', {
        cuisine: selectedCuisine.value,
        category: selectedCategory.value || undefined,
        lat: lat.value ?? undefined,
        lng: lng.value ?? undefined,
        sort: sort.value,
    })
}

function onResort() {
    resort({
        selectedCuisine: selectedCuisine.value,
        selectedCategory: selectedCategory.value,
        lat,
        lng,
        sort,
    })
}

function onLoadMore() {
    loadMore()
}

function resetToIdle() {
    setPhase('idle')
    // Fresh slate: clear the cuisine selection so the remounted CuisinePicker's
    // "any cuisine" label is honest (it owns its own selectedLabel, which resets
    // on remount — clearing the parent stops the old cuisine being silently
    // reused). City/coords/sort are intentionally kept.
    selectedCategory.value = ''
    selectedCuisine.value = undefined
    selectedLabel.value = null
    geolocationError.value = null
    resetState()
}

function refineSearch() {
    setPhase('idle')
    // Fresh slate on back/refine: clear cuisine (same reason as resetToIdle).
    // City/coords/sort are kept so the user can just re-search.
    selectedCategory.value = ''
    selectedCuisine.value = undefined
    selectedLabel.value = null
    geolocationError.value = null
}

function dismissGeolocationError() {
    geolocationError.value = null
}

function dismissLoadMoreError() {
    loadMoreError.value = null
}
</script>

<template>
    <div class="flex min-h-screen flex-col bg-background">
        <SeoMeta :seoData="seoData" />

        <!-- Structured data — Inertia <Head> drops <script> tags, so inject via JsonLd -->
        <JsonLd :data="structuredData" />

        <!-- Visually-hidden page title for accessibility -->
        <h1 class="sr-only">Find Popular Restaurants Near You</h1>

        <!-- Geolocation error banner -->
        <Transition name="fade">
            <Card v-if="geolocationError && phase === 'idle'" class="absolute left-4 right-4 top-16 z-10 mx-auto max-w-2xl border-destructive bg-destructive/10">
                <CardContent class="flex items-center justify-between py-3">
                    <div class="flex items-center gap-2">
                        <Badge variant="destructive">Location Error</Badge>
                        <span class="text-sm text-foreground">{{ geolocationError }}</span>
                    </div>
                    <Button variant="ghost" size="sm" aria-label="Dismiss" @click="dismissGeolocationError">
                        <X class="h-4 w-4" />
                    </Button>
                </CardContent>
            </Card>
        </Transition>

        <!-- Sticky compact search bar (visible in results phases) -->
        <Transition name="bar-in">
            <StickySearchBar
                v-if="isResultsPhase"
                :location="persistedLocation"
                @refine-search="refineSearch"
            />
        </Transition>

        <!-- Main content area. `relative` anchors the absolute-positioned leaving
             results on the back-transition (results-in-leave-active) to this box,
             not the viewport. -->
        <main class="relative flex flex-1 flex-col">
            <!-- Centered hero (idle phase) — Transition watches HeroBanner's own v-if -->
            <Transition name="hero-out">
                <HeroBanner
                    v-if="phase === 'idle'"
                    :categories="categories"
                    :location="persistedLocation"
                    :detecting-location="detectingLocation"
                    @cuisine-select="onCuisineSelect"
                    @location-update="onLocationUpdate"
                    @coords="onCoords"
                    @detect="detectLocation"
                    @search="onSearch"
                />
            </Transition>

            <!-- Yelp-style homepage sections — only in idle phase, no transition needed -->
            <template v-if="phase === 'idle'">
                <CategoryGrid :categories="categories" :loading="dataLoading" />

                <PopularCuisines
                    :cuisines="popularCuisines"
                    :city="effectiveLocation?.city ?? null"
                    :loading="dataLoading"
                />

                <PopularRestaurants
                    :restaurants="popularRestaurants"
                    :city="effectiveLocation?.city ?? null"
                    :loading="dataLoading"
                />
            </template>

            <!-- Results area (all non-idle phases) -->
            <Transition name="results-in">
                <ResultsGrid
                    v-if="isResultsPhase"
                    :phase="phase"
                    :restaurants="restaurants"
                    :result-count="resultCount"
                    :sort="sort"
                    :sort-options="sortOptions"
                    :next-page-url="nextPageUrl"
                    :search-error="searchError"
                    :load-more-error="loadMoreError"
                    :lat="lat"
                    :lng="lng"
                    :selected-cuisine="selectedCuisine"
                    :should-stagger="shouldStagger"
                    :is-resorting="isResorting"
                    @update:sort="sort = $event"
                    @resort="onResort"
                    @load-more="onLoadMore"
                    @reset-to-idle="resetToIdle"
                    @dismiss-load-more-error="dismissLoadMoreError"
                    @search="onSearch"
                />
            </Transition>
        </main>

        <!-- Semantic footer -->
        <footer class="border-t border-border bg-muted/40 py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                    <div class="text-center sm:text-left">
                        <h3 class="text-lg font-semibold text-foreground">iPop360</h3>
                        <p class="text-sm text-muted-foreground">Discover great restaurants near you.</p>
                    </div>
                    <nav class="flex flex-wrap items-center justify-center gap-4 text-sm sm:justify-end">
                        <a href="/" class="text-muted-foreground hover:text-foreground transition-colors">
                            Home
                        </a>
                        <a href="/restaurants" class="text-muted-foreground hover:text-foreground transition-colors">
                            Browse Restaurants
                        </a>
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/favorites"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Favorites
                        </Link>
                        <Link
                            v-if="$page.props.auth?.user"
                            href="/logout"
                            method="post"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                            as="button"
                        >
                            Logout
                        </Link>
                        <Link
                            v-else
                            href="/login"
                            class="text-muted-foreground hover:text-foreground transition-colors"
                        >
                            Login
                        </Link>
                    </nav>
                </div>
                <div class="mt-6 text-center text-xs text-muted-foreground space-y-1">
                    <p>&copy; {{ new Date().getFullYear() }} iPop360. All rights reserved.</p>
                    <p>
                        <a
                            href="https://lp.similarweb.com/competitive-analysis-youtube/"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="hover:text-foreground transition-colors"
                        >
                            Competitive analysis by Similarweb
                        </a>
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
