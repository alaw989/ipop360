<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { Navigation } from '@lucide/vue'

// Lazy-load Leaflet only when the component mounts
let L: any = null
let leafletCssLoaded = false

async function loadLeaflet() {
  if (L) return L

  // Dynamic import of Leaflet
  const leafletModule = await import('leaflet')
  L = leafletModule.default

  // Load CSS dynamically
  if (!leafletCssLoaded) {
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'
    document.head.appendChild(link)
    leafletCssLoaded = true
  }

  return L
}

const props = defineProps<{
  lat: number | null
  lng: number | null
  name: string
  address?: string | null
}>()

const mapContainer = ref<HTMLElement | null>(null)
let mapInstance: any = null
let initTimer: ReturnType<typeof setTimeout> | null = null
let disposed = false

async function initMap() {
  if (disposed || !mapContainer.value || !document.contains(mapContainer.value) || props.lat == null || props.lng == null) return

  const L = await loadLeaflet()

  // The import may have taken a moment; the page could be gone by now, and
  // Leaflet throws "Map container not found." for a missing container.
  if (disposed || !mapContainer.value) return

  mapInstance = L.map(mapContainer.value, {
    center: [props.lat, props.lng],
    zoom: 16,
    zoomControl: true,
    attributionControl: true,
    dragging: !L.Browser.mobile,
    tapHold: L.Browser.mobile,
    scrollWheelZoom: !L.Browser.mobile,
  })

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(mapInstance)

  const icon = L.divIcon({
    className: 'custom-pin',
    html: `<div style="background:#c2401c;border:3px solid white;border-radius:50%;width:18px;height:18px;box-shadow:0 2px 6px rgba(0,0,0,0.3)"></div>`,
    iconSize: [18, 18],
    iconAnchor: [9, 9],
  })

  // title names the pin for screen readers (set as a property, not HTML).
  L.marker([props.lat, props.lng], { icon, title: props.name })
    .addTo(mapInstance)
    // A DOM node, not an HTML string: the name comes from outside sources.
    .bindPopup(Object.assign(document.createElement('b'), { textContent: props.name }))

  // Fit bounds to show a small area around the marker. No animation: an
  // in-flight zoom transition can outlive the map and throw on unmount.
  mapInstance.fitBounds([
    [props.lat - 0.005, props.lng - 0.005],
    [props.lat + 0.005, props.lng + 0.005],
  ], { animate: false })
}

function destroyMap() {
  if (mapInstance) {
    // Stop any animation and clear the flag before remove() deletes the pane,
    // so Leaflet's deferred zoom-transition handler becomes a no-op.
    mapInstance.stop()
    mapInstance._animatingZoom = false
    mapInstance.remove()
    mapInstance = null
  }
}

function openDirections() {
  if (props.lat != null && props.lng != null) {
    const dest = `${props.lat},${props.lng}`
    window.open(`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(dest)}`, '_blank')
  }
}

onMounted(() => {
  initTimer = setTimeout(initMap, 200)
})

onUnmounted(() => {
  disposed = true
  if (initTimer) clearTimeout(initTimer)
  destroyMap()
})

watch(
  () => [props.lat, props.lng] as const,
  () => {
    if (initTimer) clearTimeout(initTimer)
    destroyMap()
    if (props.lat != null && props.lng != null) {
      initTimer = setTimeout(initMap, 200)
    }
  }
)
</script>

<template>
  <div class="overflow-hidden rounded-xl border border-border bg-card">
    <div ref="mapContainer" class="h-56 w-full sm:h-64" />
    <div v-if="lat && lng" class="border-t border-border px-4 py-2">
      <button
        type="button"
        class="inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-primary hover:text-primary/80 transition-colors"
        @click="openDirections"
      >
        <Navigation :size="16" aria-hidden="true" />
        Get directions
      </button>
    </div>
  </div>
</template>

<style scoped>
.custom-pin {
  background: none;
  border: none;
}
</style>
