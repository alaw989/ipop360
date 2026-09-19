<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import CuisinePicker from '@/Components/CuisinePicker.vue'
import LocationPicker from '@/Components/LocationPicker.vue'
import SearchBarShell from '@/Components/SearchBarShell.vue'
import { slides, slideSources } from '@/lib/slideshow'

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
    zip?: string | null
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

const sources = slides.map(slideSources)
const currentSlide = ref(0)
const isPaused = ref(false)
const loadedSlides = ref(slides.map(() => true))
const hero = ref<HTMLElement | null>(null)
let timer: ReturnType<typeof setInterval> | null = null

// Only the first photo loads with the page. Each later photo is added one
// slide ahead of when it's shown, so a visitor who never watches the
// slideshow downloads two photos, not five. Slides 0..renderedUpTo exist.
const renderedUpTo = ref(0)
let nextTimer: ReturnType<typeof setTimeout> | null = null

function renderThrough(index: number) {
    renderedUpTo.value = Math.min(slides.length - 1, Math.max(renderedUpTo.value, index))
    if (nextTimer) {
        clearTimeout(nextTimer)
        nextTimer = null
    }
}

function onFirstLoaded() {
    const idle = (window as Window & { requestIdleCallback?: (cb: () => void, opts?: { timeout: number }) => number }).requestIdleCallback
    if (idle) idle(() => renderThrough(1), { timeout: 3000 })
    else setTimeout(() => renderThrough(1), 1500)
}

function onSlideError(index: number) {
    loadedSlides.value[index] = false
}

function goToSlide(index: number) {
    renderThrough(index + 1)
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
    stopTimer()
    timer = setInterval(() => {
        const next = (currentSlide.value + 1) % slides.length
        if (next > renderedUpTo.value) return
        currentSlide.value = next
        renderThrough(next + 1)
    }, 6000)
}

function stopTimer() {
    if (timer) {
        clearInterval(timer)
        timer = null
    }
}

function resetTimer() {
    if (!isPaused.value) startTimer()
}

onMounted(() => {
    // Server-rendered, the first photo can finish loading before the page is
    // interactive, when its load event has already fired.
    const first = hero.value?.querySelector('img')
    if (first?.complete) onFirstLoaded()
    nextTimer = setTimeout(() => renderThrough(1), 4000)

    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        isPaused.value = true
        return
    }
    startTimer()
})

onUnmounted(() => {
    stopTimer()
    if (nextTimer) clearTimeout(nextTimer)
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
    <section ref="hero" class="relative flex min-h-[440px] flex-col overflow-hidden lg:min-h-[600px]">
        <!-- Background slideshow -->
        <div class="absolute inset-0" aria-hidden="true">
            <template v-for="(slide, i) in slides" :key="slide.id">
                <picture
                    v-if="i <= renderedUpTo"
                    class="absolute inset-0 transition-opacity duration-1000 ease-in-out"
                    :class="i === currentSlide ? 'opacity-100' : 'opacity-0'"
                >
                    <source media="(max-width: 767px)" :srcset="sources[i]!.phone" sizes="100vw" />
                    <source :srcset="sources[i]!.wide" sizes="100vw" />
                    <img
                        v-show="loadedSlides[i]"
                        :src="sources[i]!.fallback"
                        class="h-full w-full object-cover"
                        alt=""
                        decoding="async"
                        :loading="i === 0 ? 'eager' : 'lazy'"
                        :fetchpriority="i === 0 ? 'high' : 'low'"
                        @load="i === 0 && onFirstLoaded()"
                        @error="onSlideError(i)"
                    />
                </picture>
            </template>
        </div>

        <!-- Darkening so white text and the search bar read on any photo -->
        <div class="absolute inset-0 bg-gradient-to-b from-black/60 via-black/45 to-black/70" />

        <!-- Content layer -->
        <div class="relative z-10 flex flex-1 flex-col">
            <div class="mx-auto flex w-full max-w-4xl flex-1 flex-col justify-center px-4 pb-8 pt-20 sm:px-6 sm:pt-24">
                <h1 class="font-heading text-[2rem] font-bold leading-[1.1] text-white text-balance sm:text-5xl">
                    Find the most popular restaurants near you
                </h1>

                <SearchBarShell
                    size="lg"
                    layout="responsive"
                    :busy="detectingLocation"
                    class="mt-6 shadow-lg sm:mt-8"
                    @submit="$emit('search')"
                >
                    <template #what>
                        <CuisinePicker
                            variant="field"
                            size="lg"
                            :categories="categories"
                            @select="onCuisineSelect"
                        />
                    </template>
                    <template #where>
                        <LocationPicker
                            variant="field"
                            size="lg"
                            :location="location"
                            :detecting="detectingLocation"
                            @update="onLocationUpdate"
                            @coords="onCoords"
                            @detect="onDetect"
                        />
                    </template>
                </SearchBarShell>
            </div>

            <!-- Slide controls -->
            <div class="flex items-center justify-center gap-2 pb-6">
                <button
                    v-for="(_, i) in slides"
                    :key="'dot-' + i"
                    type="button"
                    class="relative flex h-11 w-11 items-center justify-center rounded-full transition-all duration-300"
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
                    type="button"
                    class="ml-3 flex h-11 w-11 items-center justify-center rounded-full text-white/60 hover:text-white hover:bg-white/10 transition-colors"
                    :aria-label="isPaused ? 'Resume slideshow' : 'Pause slideshow'"
                    @click="togglePause"
                >
                    <span v-if="isPaused" class="text-sm">▶</span>
                    <span v-else class="text-sm">⏸</span>
                </button>
            </div>
        </div>

        <!-- Photo attribution -->
        <div class="absolute bottom-2 right-3 z-10 text-[10px] text-white/50">
            {{ slides[currentSlide]?.attribution ?? '' }}
        </div>
    </section>
</template>
