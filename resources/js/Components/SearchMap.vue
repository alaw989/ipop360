<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch, computed } from 'vue';
import type { Restaurant } from '@/types/restaurant';

const props = defineProps<{
    restaurants: Restaurant[];
    lat?: string;
    lng?: string;
}>();

const mapContainer = ref<HTMLElement | null>(null);
const isExpanded = ref(false);

let map: any = null;
let markers: any[] = [];
let leaflet: any = null;

const center = computed(() => {
    if (props.lat && props.lng) {
        return [parseFloat(props.lat), parseFloat(props.lng)];
    }
    const firstWithCoords = props.restaurants.find(r => r.lat != null && r.lng != null);
    if (firstWithCoords) {
        return [firstWithCoords.lat!, firstWithCoords.lng!];
    }
    return [39.8283, -98.5795]; // center of US
});

onMounted(async () => {
    await import('leaflet/dist/leaflet.css');
    leaflet = await import('leaflet');

    if (!mapContainer.value) return;

    map = leaflet.map(mapContainer.value, {
        zoomControl: true,
        attributionControl: true,
    }).setView(center.value, 12);

    leaflet.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>',
    }).addTo(map);

    addMarkers();
});

onUnmounted(() => {
    if (map) {
        map.remove();
        map = null;
    }
});

watch(() => props.restaurants, () => {
    if (map) {
        clearMarkers();
        addMarkers();
    }
}, { deep: true });

function clearMarkers() {
    markers.forEach(m => map?.removeLayer(m));
    markers = [];
}

function addMarkers() {
    if (!map || !leaflet) return;

    const bounds: number[][] = [];
    const icon = leaflet.divIcon({
        className: '',
        html: '<div style="width:24px;height:24px;background:#10b981;border:3px solid #fff;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,.3)"></div>',
        iconSize: [24, 24],
        iconAnchor: [12, 12],
        popupAnchor: [0, -16],
    });

    props.restaurants.forEach(r => {
        if (r.lat == null || r.lng == null) return;

        const marker = leaflet.marker([r.lat, r.lng], { icon })
            .addTo(map)
            .bindPopup(`
                <div style="font-family:system-ui,sans-serif;min-width:160px">
                    <strong style="font-size:13px">${r.name}</strong>
                    ${r.yelp_rating || r.google_rating ? `<br><span style="font-size:12px;color:#666">⭐ ${r.yelp_rating || r.google_rating}</span>` : ''}
                    ${r.price_range ? `<span style="font-size:12px;color:#10b981;font-weight:600;margin-left:8px">${r.price_range}</span>` : ''}
                    <br><a href="/restaurants/${r.slug}" style="font-size:12px;color:#2563eb;text-decoration:none">View details →</a>
                </div>
            `, { closeButton: true, maxWidth: 260 });

        markers.push(marker);
        bounds.push([r.lat, r.lng]);
    });

    if (bounds.length > 0 && map) {
        map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
    }
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-card">
        <div
            ref="mapContainer"
            class="z-0"
            :class="isExpanded ? 'h-[600px]' : 'h-[calc(100vh-8rem)]'"
        />
        <div class="flex items-center justify-between border-t px-3 py-2">
            <span class="text-xs text-muted-foreground">
                {{ restaurants.filter(r => r.lat != null).length }} pinned
            </span>
            <button
                class="text-xs font-medium text-primary hover:underline"
                @click="isExpanded = !isExpanded"
            >
                {{ isExpanded ? 'Collapse' : 'Expand' }}
            </button>
        </div>
    </div>
</template>
