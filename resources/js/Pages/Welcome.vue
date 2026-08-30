<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3'
import { ref, computed, onUnmounted, watch } from 'vue'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { X } from '@lucide/vue'
import JsonLd from '@/Components/JsonLd.vue'
import AppFooter from '@/Components/AppFooter.vue'
import TopNav from '@/Components/TopNav.vue'
import HeroBanner from '@/Components/HeroBanner.vue'
import ScrollReveal from '@/Components/ScrollReveal.vue'
import PopularCities from '@/Components/PopularCities.vue'
import PopularRestaurants from '@/Components/PopularRestaurants.vue'
import BlogPreview from '@/Components/BlogPreview.vue'

import { useSeo, generateWebSiteJsonLd, generateOrganizationJsonLd } from '@/composables/useSeo'
import { useSearchLoadingOverlay } from '@/composables/useSearchLoadingOverlay'
import { useGeolocation } from '@/composables/useGeolocation'
import { useBaseUrl } from '@/composables/useBaseUrl'
import SeoMeta from '@/Components/SeoMeta.vue'
import '../../css/transitions.css' // homepage-only transition choreography (spec-062)

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

interface BlogPost {
    id: number
    title: string
    slug: string
    excerpt: string
    category: string | null
    featured_image: string | null
    published_at: string | null
    is_featured: boolean
    author?: { id: number; name: string } | null
}

interface HomepageData {
    popularRestaurants: Restaurant[]
    location: Location | null
}

const props = defineProps<{
    categories: Category[]
    popularCities: Array<{
        name: string
        city: string
        state: string
    }>
    popularRestaurants: Restaurant[]
    latestPosts: BlogPost[]
}>()

// Cuisine selection state
const selectedCategory = ref('')
const selectedCuisine = ref<string | undefined>()
const selectedLabel = ref<string | null>(null)

// Sort state (fed to the /search navigation below)
const sort = ref<string>('best_match')
const serpapiExhausted = computed(() => usePage().props.serpapi_exhausted)

// Location state. No server-side IP guessing and no cross-load persistence —
// every load starts blank until the user picks a city or grants GPS via
// "Use my current location".
const persistedLocation = ref<Location>({ city: null, state: null })
const lat = ref<number | null>(null)
const lng = ref<number | null>(null)

function setLocation(city: string | null, state: string | null, lt: number | null, lg: number | null): void {
    persistedLocation.value = { city, state }
    lat.value = lt
    lng.value = lg
}

// Geolocation (GPS + reverse geocode) — only ever triggered by the explicit
// "Use my current location" action, never automatically on load.
const { detectingLocation, geolocationError, detectLocation } = useGeolocation(setLocation)

// Full-page loading takeover for the homepage's initial search navigation
// (router.get('/search', ...) below) — the only feedback that visit had
// before was Inertia's thin top progress bar. Hosted at the app root (see
// app.ts) so its fade-out can play even after Inertia swaps this page away.
const { begin: beginSearchLoading, end: endSearchLoading } = useSearchLoadingOverlay()

// SEO
const baseUrl = useBaseUrl()

const seoData = computed(() => {
    return useSeo({
        title: 'Find Popular Restaurants Near You | iPop360',
        description: serpapiExhausted.value
            ? 'Discover popular restaurants near you with iPop360. Smart rankings help you find the best dining options in your area.'
            : 'Discover top-rated restaurants near you with iPop360. Real reviews, accurate ratings, and smart rankings help you find the best dining options in your area.',
        url: `${baseUrl.value}/`,
        type: 'website',
    })
})

const structuredData = computed(() => {
    const webSite = generateWebSiteJsonLd(`${baseUrl.value}/`, 'iPop360')
    const organization = generateOrganizationJsonLd(`${baseUrl.value}/`, 'iPop360')
    return [webSite, organization]
})

// Reactive homepage data — initialised from server props (always the
// unscoped/global view, since the server never guesses a city), then
// refetched when the user picks a city via LocationPicker or GPS.
const bannerCategories = ref<Category[]>(props.categories)
const popularRestaurants = ref<HomepageData['popularRestaurants']>(props.popularRestaurants)

// Tracks the actual location scope of the data shown (may differ from the
// selected city when no restaurants exist for it and fallback kicks in).
const effectiveLocation = ref<Location | null>(null)

// Abort controller for in-flight homepage-data fetches.
const homepageAbortController = ref<AbortController | null>(null)

function fetchHomepageData(city: string | null, state: string | null) {
    homepageAbortController.value?.abort()
    const controller = new AbortController()
    homepageAbortController.value = controller

    const params = new URLSearchParams()
    if (city) params.set('city', city)
    if (state) params.set('state', state)

    fetch(`/api/homepage-data?${params}`, { signal: controller.signal })
        .then(res => {
            if (!res.ok) return null
            return res.json() as Promise<HomepageData>
        })
        .then((data: HomepageData | null) => {
            if (!data) return
            popularRestaurants.value = data.popularRestaurants
            effectiveLocation.value = data.location
        })
        .catch(err => {
            if (err instanceof DOMException && err.name === 'AbortError') return
        })
}

watch(
    () => [persistedLocation.value?.city, persistedLocation.value?.state] as const,
    ([newCity, newState]) => {
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
    setLocation(persistedLocation.value.city, persistedLocation.value.state, lt, lg)
}

function onSearch() {
    beginSearchLoading()
    router.get('/search', {
        cuisine: selectedCuisine.value,
        category: selectedCategory.value || undefined,
        lat: lat.value ?? undefined,
        lng: lng.value ?? undefined,
        distance: '25',
        sort: sort.value,
    }, {
        onFinish: () => endSearchLoading(),
    })
}

function dismissGeolocationError() {
    geolocationError.value = null
}
</script>

<template>
    <div class="relative flex min-h-screen flex-col bg-background">
        <!-- Shared AppLayout top nav; transparent over the hero slideshow, non-sticky. -->
        <TopNav :sticky="false" :transparent="true" />

        <!-- The full-page search-loading takeover renders at the app root
             (app.ts), not here — see useSearchLoadingOverlay for why. -->

        <SeoMeta :seoData="seoData" />

        <!-- Structured data — Inertia <Head> drops <script> tags, so inject via JsonLd -->
        <JsonLd :data="structuredData" />

        <!-- Visually-hidden page title for accessibility -->
        <h1 class="sr-only">Find Popular Restaurants Near You</h1>

        <!-- Geolocation error banner -->
        <Transition name="fade">
            <Card v-if="geolocationError" class="absolute left-4 right-4 top-16 z-10 mx-auto max-w-2xl border-destructive bg-destructive/10">
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

        <main class="relative flex flex-1 flex-col">
            <HeroBanner
                :categories="bannerCategories"
                :location="persistedLocation"
                :detecting-location="detectingLocation"
                @cuisine-select="onCuisineSelect"
                @location-update="onLocationUpdate"
                @coords="onCoords"
                @detect="detectLocation"
                @search="onSearch"
            />

            <!-- Yelp-style homepage sections. Each section staggers its reveal
                 (80ms step) so above-the-fold sections cascade in instead of
                 snapping into view simultaneously. -->
            <ScrollReveal :delay="0">
                <BlogPreview :posts="props.latestPosts" />
            </ScrollReveal>

            <ScrollReveal :delay="80">
                <PopularCities
                    :cities="props.popularCities"
                    :selected-city="persistedLocation.city"
                />
            </ScrollReveal>

            <ScrollReveal :delay="160">
                <PopularRestaurants
                    :restaurants="popularRestaurants"
                    :city="effectiveLocation?.city ?? null"
                />
            </ScrollReveal>
        </main>

        <AppFooter />
    </div>
</template>
