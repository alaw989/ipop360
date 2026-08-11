import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import SearchMap from '@/Components/SearchMap.vue'
import type { Restaurant } from '@/types/restaurant'

const mockMapInstance = {
    setView: vi.fn().mockReturnThis(),
    remove: vi.fn(),
    fitBounds: vi.fn(),
    addLayer: vi.fn().mockReturnThis(),
    removeLayer: vi.fn(),
    invalidateSize: vi.fn(),
}
const mockTileLayer = { addTo: vi.fn().mockReturnThis() }
const mockMarker = {
    addTo: vi.fn().mockReturnThis(),
    bindPopup: vi.fn().mockReturnThis(),
}

const leafletMap = vi.fn(() => mockMapInstance)
const leafletTileLayer = vi.fn(() => mockTileLayer)
const leafletDivIcon = vi.fn(() => ({}))
const leafletMarker = vi.fn(() => mockMarker)

vi.mock('leaflet', () => ({
    map: leafletMap,
    tileLayer: leafletTileLayer,
    divIcon: leafletDivIcon,
    marker: leafletMarker,
}))

vi.mock('leaflet/dist/leaflet.css', () => ({}))

function makeRestaurant(overrides: Partial<Restaurant> = {}): Restaurant {
    return {
        id: 1,
        name: 'Test Place',
        slug: 'test-place',
        description: null,
        address: null,
        city: null,
        state: null,
        lat: 30.27,
        lng: -97.74,
        photo_url: null,
        price_range: '$$',
        phone: null,
        website_url: null,
        google_rating: null,
        google_review_count: 0,
        yelp_rating: null,
        yelp_review_count: 0,
        has_award: false,
        popularity_score: 0,
        rank_change: null,
        distance: null,
        cuisines: [],
        source: null,
        social_links: [],
        opening_hours: null,
        menu_url: null,
        ...overrides,
    }
}

async function mountComponent(props: Record<string, unknown> = {}) {
    const wrapper = mount(SearchMap, {
        props: { restaurants: [], ...props },
    })
    await flushPromises()
    await wrapper.vm.$nextTick()
    return wrapper
}

describe('SearchMap', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('renders the map container div', async () => {
        const wrapper = await mountComponent()
        expect(wrapper.find('.z-0').exists()).toBe(true)
    })

    it('shows "0 pinned" when no restaurants have coordinates', async () => {
        const wrapper = await mountComponent()
        expect(wrapper.text()).toContain('0 pinned')
    })

    it('counts only restaurants with lat and lng in pinned count', async () => {
        const restaurants = [
            makeRestaurant({ id: 1, name: 'A', lat: 30, lng: -97 }),
            makeRestaurant({ id: 2, name: 'B', lat: null, lng: null }),
            makeRestaurant({ id: 3, name: 'C', lat: 40, lng: -74 }),
        ]
        const wrapper = await mountComponent({ restaurants })
        expect(wrapper.text()).toContain('2 pinned')
    })

    it('shows "Expand" button by default', async () => {
        const wrapper = await mountComponent()
        expect(wrapper.text()).toContain('Expand')
    })

    it('toggles to "Collapse" when expand button is clicked', async () => {
        const wrapper = await mountComponent()
        await wrapper.find('button').trigger('click')
        expect(wrapper.text()).toContain('Collapse')
    })

    it('toggles back to "Expand" on second click', async () => {
        const wrapper = await mountComponent()
        const btn = wrapper.find('button')
        await btn.trigger('click')
        await btn.trigger('click')
        expect(wrapper.text()).toContain('Expand')
    })

    it('applies collapsed height class by default', async () => {
        const wrapper = await mountComponent()
        const mapDiv = wrapper.find('.z-0')
        expect(mapDiv.classes()).toContain('h-[calc(100vh-8rem)]')
    })

    it('switches to expanded height class on toggle', async () => {
        const wrapper = await mountComponent()
        await wrapper.find('button').trigger('click')
        const mapDiv = wrapper.find('.z-0')
        expect(mapDiv.classes()).toContain('h-[600px]')
        expect(mapDiv.classes()).not.toContain('h-[calc(100vh-8rem)]')
    })

    it('initializes a Leaflet map on mount with center coordinates', async () => {
        const restaurants = [makeRestaurant({ lat: 35, lng: -90 })]
        await mountComponent({ restaurants })
        expect(leafletMap).toHaveBeenCalledTimes(1)
        expect(mockMapInstance.setView).toHaveBeenCalledWith([35, -90], 12)
    })

    it('defaults center to geographic center of US when no coordinates available', async () => {
        await mountComponent()
        expect(leafletMap).toHaveBeenCalledTimes(1)
        expect(mockMapInstance.setView).toHaveBeenCalledWith([39.8283, -98.5795], 12)
    })

    it('adds a tile layer to the map', async () => {
        await mountComponent()
        expect(leafletTileLayer).toHaveBeenCalledTimes(1)
        expect(leafletTileLayer).toHaveBeenCalledWith(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            expect.objectContaining({ maxZoom: 19 }),
        )
        expect(mockTileLayer.addTo).toHaveBeenCalledWith(mockMapInstance)
    })

    it('creates markers for restaurants with lat/lng', async () => {
        const restaurants = [
            makeRestaurant({ id: 1, lat: 30, lng: -97, name: 'Spot A' }),
            makeRestaurant({ id: 2, lat: null, lng: null, name: 'No Coords' }),
            makeRestaurant({ id: 3, lat: 40, lng: -74, name: 'Spot C' }),
        ]
        await mountComponent({ restaurants })
        expect(leafletMarker).toHaveBeenCalledTimes(2)
        const firstCall = leafletMarker.mock.calls[0]
        expect(firstCall[0]).toEqual([30, -97])
        const secondCall = leafletMarker.mock.calls[1]
        expect(secondCall[0]).toEqual([40, -74])
    })

    it('binds a popup to each marker with restaurant name', async () => {
        const restaurants = [makeRestaurant({ id: 1, lat: 30, lng: -97, name: 'Taco World' })]
        await mountComponent({ restaurants })
        expect(mockMarker.bindPopup).toHaveBeenCalledTimes(1)
        const popupHtml = mockMarker.bindPopup.mock.calls[0][0]
        expect(popupHtml).toContain('Taco World')
    })

    it('calls fitBounds after adding markers', async () => {
        const restaurants = [
            makeRestaurant({ id: 1, lat: 30, lng: -97 }),
            makeRestaurant({ id: 2, lat: 40, lng: -74 }),
        ]
        await mountComponent({ restaurants })
        expect(mockMapInstance.fitBounds).toHaveBeenCalledWith(
            [[30, -97], [40, -74]],
            expect.objectContaining({ padding: [30, 30], maxZoom: 15 }),
        )
    })

    it('calls map.remove on unmount', async () => {
        const wrapper = await mountComponent()
        wrapper.unmount()
        expect(mockMapInstance.remove).toHaveBeenCalledTimes(1)
    })

    it('re-adds markers when restaurants prop changes', async () => {
        const wrapper = await mountComponent({ restaurants: [makeRestaurant({ id: 1, lat: 30, lng: -97 })] })
        leafletMarker.mockClear()
        mockMarker.addTo.mockClear()
        mockMapInstance.fitBounds.mockClear()

        await wrapper.setProps({
            restaurants: [makeRestaurant({ id: 2, lat: 35, lng: -80, name: 'New Place' })],
        })
        await flushPromises()
        await wrapper.vm.$nextTick()

        expect(leafletMarker).toHaveBeenCalledTimes(1)
        expect(leafletMarker).toHaveBeenCalledWith([35, -80], expect.anything())
        expect(mockMapInstance.fitBounds).toHaveBeenCalledTimes(1)
    })

    it('uses prop lat/lng for center when provided', async () => {
        await mountComponent({ lat: '42.35', lng: '-71.06' })
        expect(mockMapInstance.setView).toHaveBeenCalledWith([42.35, -71.06], 12)
    })

    it('includes rating and price_range in popup when available', async () => {
        const r = makeRestaurant({ id: 1, lat: 30, lng: -97, yelp_rating: 4.5, price_range: '$$$' })
        await mountComponent({ restaurants: [r] })
        const popupHtml = mockMarker.bindPopup.mock.calls[0][0]
        expect(popupHtml).toContain('4.5')
        expect(popupHtml).toContain('$$$')
    })

    it('includes a link to restaurant detail page in popup', async () => {
        const r = makeRestaurant({ id: 1, lat: 30, lng: -97, slug: 'taco-spot', name: 'Taco Spot' })
        await mountComponent({ restaurants: [r] })
        const popupHtml = mockMarker.bindPopup.mock.calls[0][0]
        expect(popupHtml).toContain('/restaurants/taco-spot')
    })

    it('renders the outer container with expected classes', async () => {
        const wrapper = await mountComponent()
        const outer = wrapper.find('.overflow-hidden')
        expect(outer.classes()).toContain('rounded-xl')
        expect(outer.classes()).toContain('border')
        expect(outer.classes()).toContain('bg-card')
    })
})
