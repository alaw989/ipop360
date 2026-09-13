<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import SearchBarShell from '@/Components/SearchBarShell.vue';
import CuisinePicker from '@/Components/CuisinePicker.vue';
import LocationPicker from '@/Components/LocationPicker.vue';
import { useGeolocation } from '@/composables/useGeolocation';
import { useSearchLoadingOverlay } from '@/composables/useSearchLoadingOverlay';
import { useCuisineCategories } from '@/composables/useCuisineCategories';

// The header search on every page except the home page, whose hero has its
// own. Self-contained: it keeps its own cuisine and place and runs the same
// /search visit as the hero.
const props = withDefaults(defineProps<{
    stacked?: boolean;
    size?: 'md' | 'lg';
}>(), {
    stacked: false,
    size: 'md',
});

const emit = defineEmits<{ searched: [] }>();

interface Location {
    city: string | null;
    state: string | null;
}

const page = usePage();
const pageProps = page.props as { cuisineName?: string | null; filters?: Record<string, unknown> };

const { categories, load } = useCuisineCategories();
const loadingCategories = ref(categories.value.length === 0);

// What: starts from the cuisine of the results already on screen, if any.
const filterCuisine = typeof pageProps.filters?.['cuisine'] === 'string' ? pageProps.filters['cuisine'] : undefined;
const filterCategory = typeof pageProps.filters?.['category'] === 'string' ? pageProps.filters['category'] : undefined;
const cuisine = ref<string | undefined>(filterCuisine);
const category = ref<string | undefined>(filterCategory);
const initialCuisineLabel = pageProps.cuisineName ?? null;

function onCuisineSelect(payload: { category: string; cuisine?: string; label: string }): void {
    category.value = payload.category || undefined;
    cuisine.value = payload.cuisine;
}

// Where: a picked city or the phone's location; failing that, the area of the
// results already on screen, so changing only the cuisine stays put.
const location = ref<Location>({ city: null, state: null });
const lat = ref<number | null>(null);
const lng = ref<number | null>(null);

function setLocation(city: string | null, state: string | null, lt: number | null, lg: number | null): void {
    location.value = { city, state };
    lat.value = lt;
    lng.value = lg;
}

const { detectingLocation, detectLocation } = useGeolocation(setLocation);

const currentArea = computed<{ lat: string; lng: string } | null>(() => {
    const query = (page.url ?? '').split('?')[1] ?? '';
    const params = new URLSearchParams(query);
    const areaLat = params.get('lat');
    const areaLng = params.get('lng');
    return areaLat && areaLng ? { lat: areaLat, lng: areaLng } : null;
});

const wherePlaceholder = computed(() => (currentArea.value ? 'This area' : 'City, or use my location'));

function onLocationUpdate(newLocation: Location): void {
    location.value = newLocation;
}

function onCoords(lt: number, lg: number): void {
    lat.value = lt;
    lng.value = lg;
}

const { begin: beginSearchLoading, end: endSearchLoading } = useSearchLoadingOverlay();

function submit(): void {
    const area = lat.value !== null && lng.value !== null
        ? { lat: String(lat.value), lng: String(lng.value) }
        : currentArea.value;

    beginSearchLoading();
    router.get('/search', {
        cuisine: cuisine.value,
        category: category.value,
        lat: area?.lat,
        lng: area?.lng,
        distance: '25',
        sort: 'best_match',
    }, {
        onFinish: () => endSearchLoading(),
    });
    emit('searched');
}

onMounted(() => {
    load().finally(() => {
        loadingCategories.value = false;
    });
});
</script>

<template>
    <SearchBarShell :size="props.size" :layout="props.stacked ? 'stacked' : 'row'" :busy="detectingLocation" @submit="submit">
        <template #what>
            <CuisinePicker
                variant="field"
                :size="props.size"
                :categories="categories"
                :loading="loadingCategories"
                :initial-label="initialCuisineLabel"
                @select="onCuisineSelect"
            />
        </template>
        <template #where>
            <LocationPicker
                variant="field"
                :size="props.size"
                :location="location"
                :detecting="detectingLocation"
                :placeholder="wherePlaceholder"
                @update="onLocationUpdate"
                @coords="onCoords"
                @detect="detectLocation"
            />
        </template>
    </SearchBarShell>
</template>
