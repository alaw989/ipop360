<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, type Component } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import CuisinePicker from '@/Components/CuisinePicker.vue'
import LocationPicker from '@/Components/LocationPicker.vue'
import BrandLogo from '@/Components/BrandLogo.vue'
import { Badge } from '@/components/ui/badge'
import { ChefHat, MapPin, UtensilsCrossed } from '@lucide/vue'
import { slides } from '@/lib/slideshow'
import { useCountUp } from '@/composables/useCountUp'

interface Category {
    id: number
    name: string
    slug: string
    icon: string | null
    cuisines: any[]
}

interface Location {
    city: string | null
    state: string | null
}

interface Stats {
    restaurants: number
    cuisines: number
    cities: number
}

interface Props {
    categories: Category[]
    location: Location
    detectingLocation: boolean
    stats: Stats
}

interface Emits {
    (e: 'cuisineSelect', payload: { category: string; cuisine?: string; label: string }): void
    (e: 'locationUpdate', location: Location): void
    (e: 'coords', lat: number, lng: number): void
    (e: 'detect'): void
    (e: 'search'): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

interface StatDef {
    icon: Component
    label: string
    target: () => number
}

const statDefs: StatDef[] = [
    { icon: UtensilsCrossed, label: 'Restaurants', target: () => props.stats.restaurants },
    { icon: ChefHat, label: 'Cuisines', target: () => props.stats.cuisines },
    { icon: MapPin, label: 'Cities', target: () => props.stats.cities },
]

const counts = statDefs.map((def, i) => useCountUp(def.target, 1000, i * 80))

const statsItems = computed(() =>
    statDefs.map((def, i) => ({
        icon: def.icon,
        label: def.label,
        value: counts[i]!.value,
        target: def.target(),
    })),
)

function formatNumber(value: number): string {
    return value.toLocaleString('en-US')
}

const currentSlide = ref(0)
const isPaused = ref(false)
const loadedSlides = ref(slides.map(() => true))
let timer: ReturnType<typeof setInterval> | null = null

function onSlideError(index: number) {
    loadedSlides.value[index] = false
}

function goToSlide(index: number) {
    currentSlide.value = index
    resetTimer()
}

function togglePause() {
    isPaused.value = !isPaused.value
    if (isPaused.value) {
        stopTimer()
    } else {
        startTimer()
    }
}

function startTimer() {
    timer = setInterval(() => {
        currentSlide.value = (currentSlide.value + 1) % slides.length
    }, 6000)
}

function stopTimer() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

function resetTimer() {
    stopTimer()
    startTimer()
}

onMounted(() => {
    startTimer()
})

onUnmounted(() => {
    stopTimer()
})

function onCuisineSelect(payload: { category: string; cuisine?: string; label: string }) {
    emit('cuisineSelect', payload)
}

function onLocationUpdate(newLocation: Location) {
    emit('locationUpdate', newLocation)
}

function onCoords(lt: number, lg: number) {
    emit('coords', lt, lg)
}

function onDetect() {
    emit('detect')
}
</script>

<template>
    <section class="relative flex min-h-[80vh] flex-col overflow-hidden">
        <!-- Background slideshow -->
        <div class="absolute inset-0">
            <div
                v-for="(slide, i) in slides"
                :key="i"
                class="absolute inset-0 transition-opacity duration-1000 ease-in-out"
                :class="i === currentSlide ? 'opacity-100' : 'opacity-0'"
            >
                <img
                    v-show="loadedSlides[i]"
                    :src="slide.image"
                    class="h-full w-full object-cover"
                    alt=""
                    aria-hidden="true"
                    @error="onSlideError(i)"
                />
            </div>
        </div>

        <!-- Gradient overlay -->
        <div class="absolute inset-0 bg-gradient-to-b from-black/70 via-black/60 to-black/85" />

        <!-- Content layer -->
        <div class="relative z-10 flex flex-1 flex-col">
            <!-- Centered hero content -->
            <div class="flex flex-1 flex-col items-center justify-center px-4 pb-20">
                <div class="w-full max-w-4xl text-center">
                    <!-- Logo (home link — the AppLayout TopNav owns the in-hero links now) -->
                    <Link href="/" class="mb-6 inline-flex items-center gap-2" aria-label="iPop360 home">
                        <BrandLogo class="text-[6rem] text-white sm:text-[8rem]" />
                        <Badge variant="outline" class="text-xs text-white border-white/50">Beta</Badge>
                    </Link>

                    <!-- Dynamic sentence -->
                    <h2 class="flex flex-wrap items-center justify-center gap-x-2 text-2xl font-medium leading-relaxed text-white sm:text-3xl">
                        <span>Find the most Popular</span>
                        <CuisinePicker
                            inverted
                            :categories="categories"
                            @select="onCuisineSelect"
                        />
                        <span>Restaurants in</span>
                        <LocationPicker
                            inverted
                            :location="location"
                            :detecting="detectingLocation"
                            @update="onLocationUpdate"
                            @coords="onCoords"
                            @detect="onDetect"
                        />
                    </h2>

                    <!-- Search button -->
                    <div class="mt-6">
                        <Button
                            size="lg"
                            :disabled="detectingLocation"
                            @click="$emit('search')"
                            class="relative px-8 transition-all hover:scale-105 active:scale-95"
                        >
                            <span v-if="detectingLocation" class="inline-flex items-center gap-2">
                                <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                Detecting location...
                            </span>
                            <span v-else>Search</span>
                        </Button>
                    </div>

                    <!-- Stats row -->
                    <div
                        class="hero-stats-fade mt-10 flex items-center justify-center"
                        role="list"
                        aria-label="Popularity statistics"
                    >
                        <div class="flex items-center">
                            <template v-for="(item, i) in statsItems" :key="item.label">
                                <div
                                    v-if="i > 0"
                                    class="h-10 w-px bg-white/20"
                                    aria-hidden="true"
                                />
                                <div
                                    class="flex flex-col items-center px-3 sm:px-10"
                                    role="listitem"
                                    :aria-label="`${formatNumber(item.target)} ${item.label}`"
                                >
                                    <div class="flex items-baseline gap-1.5 sm:gap-2">
                                        <component :is="item.icon" class="h-4 w-4 text-white/70 sm:h-5 sm:w-5" aria-hidden="true" />
                                        <span class="text-2xl font-bold tabular-nums text-white sm:text-4xl" aria-hidden="true">
                                            {{ formatNumber(item.value) }}
                                        </span>
                                    </div>
                                    <span class="mt-1 text-xs uppercase tracking-widest text-white/70 sm:text-sm" aria-hidden="true">
                                        {{ item.label }}
                                    </span>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slide controls -->
            <div class="flex items-center justify-center gap-2 pb-6">
                <button
                    v-for="(_, i) in slides"
                    :key="'dot-' + i"
                    class="rounded-full transition-all duration-300"
                    :class="i === currentSlide
                        ? 'h-2.5 w-6 bg-white'
                        : 'h-2.5 w-2.5 bg-white/50 hover:bg-white/70'"
                    :aria-label="`Go to slide ${i + 1}`"
                    @click="goToSlide(i)"
                />
                <button
                    class="ml-3 flex h-8 w-8 items-center justify-center rounded-full text-white/60 hover:text-white hover:bg-white/10 transition-colors"
                    :aria-label="isPaused ? 'Resume slideshow' : 'Pause slideshow'"
                    @click="togglePause"
                >
                    <span v-if="isPaused" class="text-sm">▶</span>
                    <span v-else class="text-sm">⏸</span>
                </button>
            </div>
        </div>

        <!-- Photo attribution -->
        <div class="absolute bottom-2 right-3 z-10 text-[10px] text-white/40">
            {{ slides[currentSlide]?.attribution ?? '' }}
        </div>
    </section>
</template>
