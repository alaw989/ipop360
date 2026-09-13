import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import DetailMap from '@/Components/DetailMap.vue'

const mockMapInstance = {
    remove: vi.fn(),
    stop: vi.fn().mockReturnThis(),
    fitBounds: vi.fn(),
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
const mockBrowser = { mobile: false }

vi.mock('leaflet', () => ({
    default: {
        map: leafletMap,
        tileLayer: leafletTileLayer,
        divIcon: leafletDivIcon,
        marker: leafletMarker,
        Browser: mockBrowser,
    },
}))

vi.mock('leaflet/dist/leaflet.css', () => ({}))

const wrappers: any[] = []

async function mountComponent(props: Record<string, unknown> = {}) {
    const wrapper = mount(DetailMap, {
        props: { name: 'Test Place', ...props },
        attachTo: document.body,
    })
    wrappers.push(wrapper)
    vi.advanceTimersByTime(200)
    await flushPromises()
    await wrapper.vm.$nextTick()
    return wrapper
}

describe('DetailMap', () => {
    beforeEach(() => {
        vi.useFakeTimers()
        vi.clearAllMocks()
        mockBrowser.mobile = false
    })

    afterEach(() => {
        for (const wrapper of wrappers) {
            if (wrapper.element.isConnected) wrapper.unmount()
        }
        wrappers.length = 0
        vi.useRealTimers()
    })

    it('renders the map container div', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        expect(wrapper.find('.h-56').exists()).toBe(true)
    })

    it('renders the outer container with expected classes', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        const outer = wrapper.find('.overflow-hidden')
        expect(outer.classes()).toContain('rounded-xl')
        expect(outer.classes()).toContain('border')
        expect(outer.classes()).toContain('bg-card')
    })

    it('initializes a Leaflet map on mount with center coordinates', async () => {
        await mountComponent({ lat: 35, lng: -90 })
        expect(leafletMap).toHaveBeenCalledTimes(1)
        expect(leafletMap).toHaveBeenCalledWith(
            expect.any(HTMLElement),
            expect.objectContaining({ center: [35, -90], zoom: 16 }),
        )
    })

    it('passes zoomControl and attributionControl options to map', async () => {
        await mountComponent({ lat: 30, lng: -97 })
        expect(leafletMap).toHaveBeenCalledWith(
            expect.any(HTMLElement),
            expect.objectContaining({ zoomControl: true, attributionControl: true }),
        )
    })

    it('enables dragging and scroll-wheel zoom on desktop', async () => {
        await mountComponent({ lat: 30, lng: -97 })
        expect(leafletMap).toHaveBeenCalledWith(
            expect.any(HTMLElement),
            expect.objectContaining({ dragging: true, tapHold: false, scrollWheelZoom: true }),
        )
    })

    it('disables one-finger dragging and scroll-wheel zoom on mobile so the page can scroll', async () => {
        mockBrowser.mobile = true
        await mountComponent({ lat: 30, lng: -97 })
        expect(leafletMap).toHaveBeenCalledWith(
            expect.any(HTMLElement),
            expect.objectContaining({ dragging: false, tapHold: true, scrollWheelZoom: false }),
        )
    })

    it('adds a tile layer to the map', async () => {
        await mountComponent({ lat: 30, lng: -97 })
        expect(leafletTileLayer).toHaveBeenCalledTimes(1)
        expect(leafletTileLayer).toHaveBeenCalledWith(
            'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
            expect.objectContaining({ maxZoom: 19 }),
        )
        expect(mockTileLayer.addTo).toHaveBeenCalledWith(mockMapInstance)
    })

    it('creates a marker at the given lat/lng', async () => {
        await mountComponent({ lat: 42, lng: -71, name: 'Boston Spot' })
        expect(leafletMarker).toHaveBeenCalledTimes(1)
        expect(leafletMarker).toHaveBeenCalledWith(
            [42, -71],
            expect.objectContaining({ icon: expect.any(Object) }),
        )
        expect(mockMarker.addTo).toHaveBeenCalledWith(mockMapInstance)
    })

    it('binds a popup to the marker with restaurant name', async () => {
        await mountComponent({ lat: 30, lng: -97, name: 'Taco Palace' })
        expect(mockMarker.bindPopup).toHaveBeenCalledTimes(1)
        const popup = mockMarker.bindPopup.mock.calls[0][0] as HTMLElement
        expect(popup.textContent).toBe('Taco Palace')
    })

    it('puts the name in the popup as text, never as HTML', async () => {
        await mountComponent({ lat: 30, lng: -97, name: '<img src=x onerror=alert(1)>' })
        const popup = mockMarker.bindPopup.mock.calls[0][0] as HTMLElement
        expect(popup.querySelector('img')).toBeNull()
        expect(popup.textContent).toBe('<img src=x onerror=alert(1)>')
    })

    it('calls fitBounds after adding marker', async () => {
        await mountComponent({ lat: 40, lng: -74 })
        expect(mockMapInstance.fitBounds).toHaveBeenCalledTimes(1)
        expect(mockMapInstance.fitBounds).toHaveBeenCalledWith(
            [
                [40 - 0.005, -74 - 0.005],
                [40 + 0.005, -74 + 0.005],
            ],
            expect.objectContaining({ animate: false }),
        )
    })

    it('creates a divIcon in the brand color', async () => {
        await mountComponent({ lat: 30, lng: -97 })
        expect(leafletDivIcon).toHaveBeenCalledTimes(1)
        expect(leafletDivIcon).toHaveBeenCalledWith(
            expect.objectContaining({
                className: 'custom-pin',
                html: expect.stringContaining('#c2401c'),
                iconSize: [18, 18],
                iconAnchor: [9, 9],
            }),
        )
    })

    it('calls map.remove on unmount', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        wrapper.unmount()
        expect(mockMapInstance.remove).toHaveBeenCalledTimes(1)
    })

    it('stops the map before removing it, so no animation is left running', async () => {
        const order: string[] = []
        mockMapInstance.stop.mockImplementationOnce(() => { order.push('stop'); return mockMapInstance })
        mockMapInstance.remove.mockImplementationOnce(() => { order.push('remove') })

        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        wrapper.unmount()

        expect(order).toEqual(['stop', 'remove'])
    })

    it('does not touch a container that is gone when leaflet finishes loading', async () => {
        // The map init awaits the leaflet import; if the page is left in the
        // meantime, Leaflet would throw "Map container not found.".
        leafletMap.mockClear()
        const wrapper = mount(DetailMap, {
            props: { name: 'Test Place', lat: 30, lng: -97 },
            attachTo: document.body,
        })
        vi.advanceTimersByTime(200)
        wrapper.unmount()
        await flushPromises()
        await wrapper.vm.$nextTick()
        expect(leafletMap).not.toHaveBeenCalled()
    })

    it('renders "Get directions" button when lat and lng are provided', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        expect(wrapper.text()).toContain('Get directions')
    })

    it('does not render "Get directions" button when lat is null', async () => {
        const wrapper = await mountComponent({ lat: null, lng: -97 })
        expect(wrapper.text()).not.toContain('Get directions')
    })

    it('does not render "Get directions" button when lng is null', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: null })
        expect(wrapper.text()).not.toContain('Get directions')
    })

    it('clicking "Get directions" opens Google Maps in a new tab', async () => {
        const windowOpen = vi.fn()
        vi.stubGlobal('open', windowOpen)
        const wrapper = await mountComponent({ lat: 30.27, lng: -97.74 })
        await wrapper.find('button').trigger('click')
        expect(windowOpen).toHaveBeenCalledWith(
            `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent('30.27,-97.74')}`,
            '_blank',
        )
        vi.unstubAllGlobals()
    })

    it('re-initializes map when lat/lng prop changes', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        leafletMap.mockClear()
        leafletMarker.mockClear()
        mockMapInstance.fitBounds.mockClear()
        mockMapInstance.remove.mockClear()

        await wrapper.setProps({ lat: 42, lng: -71 })
        vi.advanceTimersByTime(200)
        await flushPromises()
        await wrapper.vm.$nextTick()

        expect(mockMapInstance.remove).toHaveBeenCalledTimes(1)
        expect(leafletMap).toHaveBeenCalledTimes(1)
        expect(leafletMap).toHaveBeenCalledWith(
            expect.any(HTMLElement),
            expect.objectContaining({ center: [42, -71] }),
        )
        expect(leafletMarker).toHaveBeenCalledTimes(1)
        expect(mockMapInstance.fitBounds).toHaveBeenCalledTimes(1)
    })

    it('does not initialize map when lat is null', async () => {
        await mountComponent({ lat: null, lng: -97 })
        expect(leafletMap).not.toHaveBeenCalled()
    })

    it('does not initialize map when lng is null', async () => {
        await mountComponent({ lat: 30, lng: null })
        expect(leafletMap).not.toHaveBeenCalled()
    })

    it('initializes map container with correct height classes', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        const container = wrapper.find('.h-56')
        expect(container.classes()).toContain('h-56')
        expect(container.classes()).toContain('sm:h-64')
        expect(container.classes()).toContain('w-full')
    })

    it('does not initialize the map when unmounted before the init timer fires', async () => {
        const wrapper = mount(DetailMap, {
            props: { name: 'Test Place', lat: 30, lng: -97 },
            attachTo: document.body,
        })
        wrapper.unmount()
        vi.advanceTimersByTime(200)
        await flushPromises()
        expect(leafletMap).not.toHaveBeenCalled()
    })

    it('does not initialize the map when the container is removed from the DOM', async () => {
        const wrapper = mount(DetailMap, {
            props: { name: 'Test Place', lat: 30, lng: -97 },
            attachTo: document.body,
        })
        wrapper.find('.h-56').element.remove()
        vi.advanceTimersByTime(200)
        await flushPromises()
        expect(leafletMap).not.toHaveBeenCalled()
        wrapper.unmount()
    })

    it('clears the pending init timer when unmounting after a lat/lng change', async () => {
        const wrapper = await mountComponent({ lat: 30, lng: -97 })
        leafletMap.mockClear()
        await wrapper.setProps({ lat: 42, lng: -71 })
        wrapper.unmount()
        vi.advanceTimersByTime(200)
        await flushPromises()
        expect(leafletMap).not.toHaveBeenCalled()
    })
})
