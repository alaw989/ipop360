import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const {
  mockCallPhone,
  mockOpenWebsite,
  mockTrackDirections,
  mockDirectionsUrl,
} = vi.hoisted(() => ({
  mockCallPhone: vi.fn(),
  mockOpenWebsite: vi.fn(),
  mockTrackDirections: vi.fn(),
  mockDirectionsUrl: vi.fn((lat: number, lng: number) => `https://maps.google.com/?q=${lat},${lng}`),
}))

vi.mock('@/lib/restaurant', () => ({
  callPhone: mockCallPhone,
  openWebsite: mockOpenWebsite,
  trackDirections: mockTrackDirections,
  directionsUrl: mockDirectionsUrl,
}))

import RestaurantActionBar from '@/Components/RestaurantActionBar.vue'

function makeRestaurant(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    name: 'Test Restaurant',
    slug: 'test-restaurant',
    description: null,
    address: null,
    city: null,
    state: null,
    lat: null,
    lng: null,
    photo_url: null,
    price_range: null,
    phone: null,
    website_url: null,
    google_rating: null,
    google_review_count: 0,
    yelp_rating: null,
    yelp_review_count: 0,
    has_award: false,
    popularity_score: 50,
    rank_change: null,
    distance: null,
    cuisines: [],
    source: null,
    ...overrides,
  }
}

function mountBar(overrides: Record<string, unknown> = {}) {
  return mount(RestaurantActionBar, {
    props: { restaurant: makeRestaurant(overrides) as any },
  })
}

describe('RestaurantActionBar', () => {
  beforeEach(() => {
    mockCallPhone.mockClear()
    mockOpenWebsite.mockClear()
    mockTrackDirections.mockClear()
    mockDirectionsUrl.mockClear()
  })

  it('renders nothing when there is no phone, website, or coordinates', () => {
    const wrapper = mountBar()
    expect(wrapper.find('[data-testid="restaurant-action-bar"]').exists()).toBe(false)
  })

  it('renders the action bar when phone is present', () => {
    const wrapper = mountBar({ phone: '555-1234' })
    expect(wrapper.find('[data-testid="restaurant-action-bar"]').exists()).toBe(true)
  })

  it('renders the action bar when coordinates are present', () => {
    const wrapper = mountBar({ lat: 40.7, lng: -74.0 })
    expect(wrapper.find('[data-testid="restaurant-action-bar"]').exists()).toBe(true)
  })

  it('renders the action bar when website is present', () => {
    const wrapper = mountBar({ website_url: 'https://example.com' })
    expect(wrapper.find('[data-testid="restaurant-action-bar"]').exists()).toBe(true)
  })

  it('shows a Call button when phone is present', () => {
    const wrapper = mountBar({ phone: '555-1234' })
    expect(wrapper.text()).toContain('Call')
  })

  it('shows a Directions link when coordinates are present', () => {
    const wrapper = mountBar({ lat: 40.7, lng: -74.0 })
    const dir = wrapper.findAll('a').find((a) => a.text().includes('Directions'))
    expect(dir).toBeTruthy()
    expect(dir!.attributes('href')).toBe('https://maps.google.com/?q=40.7,-74')
  })

  it('shows a Website button when website is present', () => {
    const wrapper = mountBar({ website_url: 'https://example.com' })
    expect(wrapper.text()).toContain('Website')
  })

  it('calls callPhone when the Call button is clicked', async () => {
    const wrapper = mountBar({ phone: '555-1234', id: 42 })
    const callBtn = wrapper.findAll('button').find((b) => b.text().includes('Call'))
    await callBtn!.trigger('click')
    expect(mockCallPhone).toHaveBeenCalledWith('555-1234', 42)
  })

  it('calls openWebsite when the Website button is clicked', async () => {
    const wrapper = mountBar({ website_url: 'https://example.com', id: 7 })
    const webBtn = wrapper.findAll('button').find((b) => b.text().includes('Website'))
    await webBtn!.trigger('click')
    expect(mockOpenWebsite).toHaveBeenCalledWith('https://example.com', 7)
  })

  it('calls trackDirections when the Directions link is clicked', async () => {
    const wrapper = mountBar({ lat: 40.7, lng: -74.0, id: 55 })
    const dir = wrapper.findAll('a').find((a) => a.text().includes('Directions'))
    await dir!.trigger('click')
    expect(mockTrackDirections).toHaveBeenCalledWith(55)
  })

  it('is hidden on desktop screens', () => {
    const wrapper = mountBar({ phone: '555-1234' })
    expect(wrapper.find('[data-testid="restaurant-action-bar"]').classes()).toContain('md:hidden')
  })

  it('reserves safe-area bottom padding for notched devices', () => {
    const wrapper = mountBar({ phone: '555-1234' })
    expect(wrapper.find('[data-testid="restaurant-action-bar"]').classes()).toContain('pb-[env(safe-area-inset-bottom)]')
  })
})
