<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'

interface Location {
    city: string | null
    state: string | null
}

interface CityResult {
    city: string
    state: string | null
    country: string | null
    lat: number
    lng: number
    display: string | null
}

const props = defineProps<{
    location: Location | null
    detecting?: boolean
    inverted?: boolean
}>()

const emit = defineEmits<{
    update: [location: Location]
    coords: [lat: number, lng: number]
    detect: []
    cycle: [payload: { city: string; state: string | null; lat: number; lng: number }]
}>()

const open = ref(false)
const query = ref('')
const results = ref<CityResult[]>([])
const searching = ref(false)
const selectedIndex = ref(-1)
interface CycleCity {
    city: string
    state: string | null
    lat?: number
    lng?: number
}
const cycleCity = ref<CycleCity | null>(null)
let cycleTimer: ReturnType<typeof setInterval> | null = null

const displayText = computed(() => {
    if (props.detecting) return 'Detecting...'
    if (props.location?.city && props.location?.state) {
        return `${props.location.city}, ${props.location.state}`
    }
    if (props.location?.city) return props.location.city
    if (cycleCity.value?.city) {
        return cycleCity.value.state
            ? `${cycleCity.value.city}, ${cycleCity.value.state}`
            : cycleCity.value.city
    }
    return 'your city'
})

function useMyLocation() {
    open.value = false
    emit('detect')
}

function fetchRandomCity() {
    import('@/lib/api').then(({ get }) => {
        get<{ city: string; state: string | null; lat?: number; lng?: number } | null>('/api/random-city').then(data => {
            if (data?.city) {
                cycleCity.value = data
                if (data.lat != null && data.lng != null) {
                    emit('cycle', {
                        city: data.city,
                        state: data.state,
                        lat: data.lat,
                        lng: data.lng,
                    })
                }
            }
        }).catch(() => {})
    })
}

function startCycling() {
    fetchRandomCity()
    cycleTimer = setInterval(fetchRandomCity, 5000)
}

function stopCycling() {
    if (cycleTimer) {
        clearInterval(cycleTimer)
        cycleTimer = null
    }
}

let debounceTimer: ReturnType<typeof setTimeout> | null = null

watch(query, (val) => {
    if (debounceTimer) clearTimeout(debounceTimer)
    if (val.length < 2) {
        results.value = []
        return
    }
    searching.value = true
    debounceTimer = setTimeout(async () => {
        try {
            const { get } = await import('@/lib/api')
            const data = await get<any[]>(`/api/geocode/search?q=${encodeURIComponent(val)}`)
            results.value = data ?? []
            selectedIndex.value = -1
        } catch {
            results.value = []
        } finally {
            searching.value = false
        }
    }, 300)
})

function selectResult(result: CityResult) {
    emit('update', { city: result.city, state: result.state })
    emit('coords', result.lat, result.lng)
    open.value = false
    query.value = ''
    results.value = []
}

function onKeydown(e: KeyboardEvent) {
    if (results.value.length === 0) return
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        selectedIndex.value = Math.min(selectedIndex.value + 1, results.value.length - 1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        selectedIndex.value = Math.max(selectedIndex.value - 1, 0)
    } else if (e.key === 'Enter' && selectedIndex.value >= 0) {
        e.preventDefault()
        selectResult(results.value[selectedIndex.value])
    } else if (e.key === 'Escape') {
        open.value = false
    }
}

onMounted(() => {
    startCycling()
})

onUnmounted(() => {
    stopCycling()
})

watch(open, (isOpen) => {
    if (isOpen) {
        stopCycling()
    } else {
        startCycling()
    }
})
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                class="inline-flex items-center gap-1 border-b-2 px-1 font-semibold transition-all focus:outline-none"
                :class="[
                    inverted
                        ? 'border-white/30 text-white/80 hover:border-white hover:text-white'
                        : 'border-foreground/30 text-foreground hover:border-foreground',
                    detecting ? 'animate-pulse' : '',
                ]"
            >
                <svg v-if="detecting" xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 animate-spin text-primary" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <Transition name="city-cycle" mode="out-in">
                    <span :key="displayText">{{ displayText }}</span>
                </Transition>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 opacity-50" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                </svg>
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-80 p-0" align="start">
            <div class="flex flex-col">
                <!-- Search input -->
                <div class="relative border-b border-border">
                    <svg xmlns="http://www.w3.org/2000/svg" class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.3-4.3"/>
                    </svg>
                    <input
                        v-model="query"
                        @keydown="onKeydown"
                        ref="searchInput"
                        type="text"
                        placeholder="Type your city..."
                        class="w-full bg-transparent py-3 pl-10 pr-4 text-sm outline-none placeholder:text-muted-foreground"
                        autocomplete="off"
                    />
                    <span v-if="searching" class="absolute right-3 top-1/2 -translate-y-1/2">
                        <span class="inline-block h-3.5 w-3.5 animate-spin rounded-full border-2 border-primary border-t-transparent"/>
                    </span>
                </div>

                <!-- Results -->
                <div class="max-h-64 overflow-y-auto">
                    <div v-if="query.length < 2" class="flex flex-col items-center gap-3 px-4 py-6">
                        <p class="text-xs text-muted-foreground">Type to search cities</p>
                        <button @click="useMyLocation" class="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/>
                                <path d="M2 12h20"/>
                            </svg>
                            Use my current location
                        </button>
                    </div>
                    <div v-else-if="results.length === 0 && !searching" class="px-4 py-6 text-center text-xs text-muted-foreground">
                        No cities found
                    </div>
                    <button
                        v-for="(result, i) in results"
                        :key="i"
                        @click="selectResult(result)"
                        @mouseenter="selectedIndex = i"
                        class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition-colors hover:bg-accent"
                        :class="selectedIndex === i ? 'bg-accent' : ''"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ result.city }}{{ result.state ? ', ' + result.state : '' }}</p>
                            <p v-if="result.display" class="truncate text-xs text-muted-foreground">{{ result.display }}</p>
                        </div>
                    </button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>

<style scoped>
.city-cycle-enter-active,
.city-cycle-leave-active {
    transition: opacity 0.4s ease;
}
.city-cycle-enter-from,
.city-cycle-leave-to {
    opacity: 0;
}
</style>
