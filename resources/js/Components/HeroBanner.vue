<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import CuisinePicker from '@/Components/CuisinePicker.vue'
import LocationPicker from '@/Components/LocationPicker.vue'
import BrandLogo from '@/Components/BrandLogo.vue'
import { Badge } from '@/components/ui/badge'
import { slides } from '@/lib/slideshow'

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

interface Props {
    categories: Category[]
    location: Location
    detectingLocation: boolean
}

interface Emits {
    (e: 'cuisineSelect', payload: { category: string; cuisine?: string; label: string }): void
    (e: 'locationUpdate', location: Location): void
    (e: 'coords', lat: number, lng: number): void
    (e: 'detect'): void
    (e: 'search'): void
}

defineProps<Props>()
const emit = defineEmits<Emits>()

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
                        <Badge variant="outline" class="text-xs text-white border-white/50" aria-hidden="true">Beta</Badge>
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
                </div>
            </div>

            <!-- Slide controls -->
            <div class="flex items-center justify-center gap-2 pb-6">
                <button
                    v-for="(_, i) in slides"
                    :key="'dot-' + i"
                    class="relative flex h-7 w-7 items-center justify-center rounded-full transition-all duration-300"
                    :aria-label="`Go to slide ${i + 1}`"
                    @click="goToSlide(i)"
                >
                    <span
                        class="block rounded-full transition-all duration-300"
                        :class="i === currentSlide
                            ? 'h-2.5 w-6 bg-white'
                            : 'h-2.5 w-2.5 bg-white/50 hover:bg-white/70'"
                    />
                </button>
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
