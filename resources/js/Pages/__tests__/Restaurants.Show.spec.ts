import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import RestaurantsShow from '@/Pages/Restaurants/Show.vue'

const {
  mockCallPhone,
  mockOpenWebsite,
  mockTrackDirections,
  mockTrackPageview,
  mockTrackMenuClick,
  mockDirectionsUrl,
  isFavoritedMock,
  toggleMock,
} = vi.hoisted(() => ({
  mockCallPhone: vi.fn(),
  mockOpenWebsite: vi.fn(),
  mockTrackDirections: vi.fn(),
  mockTrackPageview: vi.fn(),
  mockTrackMenuClick: vi.fn(),
  mockDirectionsUrl: vi.fn((lat: number, lng: number) => `https://maps.google.com/?q=${lat},${lng}`),
  isFavoritedMock: vi.fn(() => false),
  toggleMock: vi.fn(),
}))

vi.mock('@inertiajs/vue3', async () => {
  const actual = await vi.importActual('@inertiajs/vue3')
  return {
    ...actual,
    Head: { template: '<div />' },
    usePage: () => ({
      props: {
        auth: { user: null, favorites: [] },
      },
    }),
  }
})

vi.mock('@/composables/useSeo', () => ({
  useSeo: (opts: Record<string, unknown>) => ({
    title: opts.title,
    description: opts.description,
    canonical: opts.url,
    ogTitle: opts.title,
    ogDescription: opts.description,
    ogType: 'restaurant',
    ogUrl: opts.url,
    ogImage: (opts as any).image || null,
    ogSiteName: 'iPop360',
    twitterCard: 'summary_large_image',
  }),
  generateRestaurantJsonLd: vi.fn(() => ({ '@type': 'Restaurant', name: 'Test' })),
}))

vi.mock('@/composables/useBaseUrl', () => ({
  useBaseUrl: () => ({ value: 'http://localhost' }),
}))

vi.mock('@/composables/useFavorites', () => ({
  useFavorites: () => ({
    isFavorited: isFavoritedMock,
    toggle: toggleMock,
  }),
}))

vi.mock('@/composables/useRestaurantDisplay', () => ({
  getRestaurantGradient: vi.fn(() => 'linear-gradient(to bottom, #1a1a2e, #16213e)'),
}))

vi.mock('@/lib/restaurant', () => ({
  callPhone: mockCallPhone,
  openWebsite: mockOpenWebsite,
  trackDirections: mockTrackDirections,
  trackPageview: mockTrackPageview,
  trackMenuClick: mockTrackMenuClick,
  directionsUrl: mockDirectionsUrl,
}))

watch: false

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
    photos: [] as string[],
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
    cuisines: [] as { id: number; name: string; slug: string }[],
    source: null,
    score_breakdown: null as { signals: unknown[]; total: number } | null,
    social_links: [] as { platform: string; url: string; followers: number | null }[],
    opening_hours: null as { structured: boolean } | null,
    menu_url: null,
    ...overrides,
  }
}

const stubs = {
  AppLayout: { template: '<div><slot /></div>' },
  SeoMeta: { template: '<div />' },
  JsonLd: { template: '<div />' },
  StarRating: { template: '<div class="star-rating-stub" />', props: ['rating', 'source', 'reviewCount', 'size'] },
  ScoreBreakdown: { template: '<div class="score-breakdown-stub" />', props: ['breakdown'] },
  DetailMap: { template: '<div class="detail-map-stub" />', props: ['lat', 'lng', 'name', 'address'] },
  CardGallery: { template: '<div class="card-gallery-stub" />', props: ['photos', 'gradient', 'alt', 'aspect', 'multi', 'eager', 'roundedClass'] },
  SocialLinks: { template: '<div class="social-links-stub" />', props: ['links', 'restaurantId'] },
  OpeningHours: { template: '<div class="opening-hours-stub" />', props: ['hours'] },
  Card: { template: '<div class="card-stub"><slot /></div>' },
  CardContent: { template: '<div class="card-content-stub"><slot /></div>' },
  Badge: { template: '<span class="badge-stub"><slot /></span>', props: ['variant'] },
}

function defaultProps(overrides: Record<string, unknown> = {}) {
  return {
    categorySlug: null,
    canonicalUrl: null,
    isLivePreview: false,
    restaurant: makeRestaurant(),
    ...overrides,
  }
}

function mountComponent(propsOverrides: Record<string, unknown> = {}) {
  return mount(RestaurantsShow, {
    props: defaultProps(propsOverrides),
    global: { stubs },
  })
}

beforeEach(() => {
  mockCallPhone.mockClear()
  mockOpenWebsite.mockClear()
  mockTrackDirections.mockClear()
  mockTrackPageview.mockClear()
  mockTrackMenuClick.mockClear()
  mockDirectionsUrl.mockClear()
  isFavoritedMock.mockClear()
  toggleMock.mockClear()
  isFavoritedMock.mockReturnValue(false)
})

describe('Restaurants/Show', () => {
  describe('restaurant name and header', () => {
    it('renders the restaurant name in an h1', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ name: 'Pizza Paradise' }) })
      expect(wrapper.find('h1').text()).toBe('Pizza Paradise')
    })

    it('shows the award badge when has_award is true', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ has_award: true }) })
      expect(wrapper.text()).toContain('⭐')
    })

    it('hides the award badge when has_award is false', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ has_award: false }) })
      expect(wrapper.text()).not.toContain('⭐')
    })
  })

  describe('description', () => {
    it('renders description when present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ description: 'A lovely Italian eatery.' }),
      })
      expect(wrapper.text()).toContain('A lovely Italian eatery.')
    })

    it('does not render description paragraph when absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ description: null }) })
      const paragraphs = wrapper.findAll('p')
      const hasDescription = paragraphs.some(p => p.text() === 'A lovely Italian eatery.')
      expect(hasDescription).toBe(false)
    })
  })

  describe('cuisine badges', () => {
    it('renders badges for each cuisine', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({
          cuisines: [
            { id: 1, name: 'Italian', slug: 'italian' },
            { id: 2, name: 'Pizza', slug: 'pizza' },
          ],
        }),
      })
      const badges = wrapper.findAll('.badge-stub')
      expect(badges.length).toBe(2)
      expect(badges[0].text()).toBe('Italian')
      expect(badges[1].text()).toBe('Pizza')
    })
  })

  describe('price range', () => {
    it('shows price range when present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ price_range: '$$$' }),
      })
      expect(wrapper.text()).toContain('$$$')
    })

    it('hides price range when absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ price_range: null }) })
      expect(wrapper.text()).not.toContain('$$$')
    })
  })

  describe('back link', () => {
    it('links to cuisine-filtered results when categorySlug is set', () => {
      const wrapper = mountComponent({
        categorySlug: 'italian-cuisine',
        restaurant: makeRestaurant({
          cuisines: [{ id: 1, name: 'Italian', slug: 'italian' }],
        }),
      })
      const backLink = wrapper.findAll('a').find(el => el.text().includes('Back to results'))
      expect(backLink).toBeTruthy()
      expect(backLink!.attributes('href')).toBe('/restaurants?cuisine=italian')
    })

    it('links to /restaurants when no categorySlug', () => {
      const wrapper = mountComponent({ categorySlug: null })
      const backLink = wrapper.findAll('a').find(el => el.text().includes('Back to results'))
      expect(backLink).toBeTruthy()
      expect(backLink!.attributes('href')).toBe('/restaurants')
    })
  })

  describe('address', () => {
    it('renders address with city, state, postal_code', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({
          address: '123 Main St',
          city: 'New York',
          state: 'NY',
          postal_code: '10001',
        }),
      })
      expect(wrapper.text()).toContain('123 Main St')
      expect(wrapper.text()).toContain('New York')
      expect(wrapper.text()).toContain('NY')
      expect(wrapper.text()).toContain('10001')
    })

    it('does not render address section when address is null', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ address: null, city: null, state: null }),
      })
      expect(wrapper.text()).not.toContain('MapPin')
    })
  })

  describe('phone', () => {
    it('renders phone button when phone is present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ phone: '555-1234' }),
      })
      const buttons = wrapper.findAll('button')
      const phoneBtn = buttons.find(b => b.text().includes('555-1234'))
      expect(phoneBtn).toBeTruthy()
    })

    it('calls callPhone on click', async () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ phone: '555-1234', id: 42 }),
      })
      const buttons = wrapper.findAll('button')
      const phoneBtn = buttons.find(b => b.text().includes('555-1234'))
      await phoneBtn!.trigger('click')
      expect(mockCallPhone).toHaveBeenCalledWith('555-1234', 42)
    })

    it('does not render phone when absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ phone: null }) })
      const buttons = wrapper.findAll('button')
      const hasPhone = buttons.some(b => b.text().includes('555'))
      expect(hasPhone).toBe(false)
    })
  })

  describe('website', () => {
    it('renders website button with cleaned URL', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ website_url: 'https://www.example.com' }),
      })
      const buttons = wrapper.findAll('button')
      const webBtn = buttons.find(b => b.text().includes('www.example.com'))
      expect(webBtn).toBeTruthy()
    })

    it('calls openWebsite on click', async () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ website_url: 'https://example.com', id: 7 }),
      })
      const buttons = wrapper.findAll('button')
      const webBtn = buttons.find(b => b.text().includes('example.com'))
      await webBtn!.trigger('click')
      expect(mockOpenWebsite).toHaveBeenCalledWith('https://example.com', 7)
    })

    it('does not render website when absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ website_url: null }) })
      const buttons = wrapper.findAll('button')
      const hasWeb = buttons.some(b => b.text().includes('http'))
      expect(hasWeb).toBe(false)
    })
  })

  describe('menu', () => {
    it('renders menu button when menu_url is present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ menu_url: 'https://menu.example.com' }),
      })
      expect(wrapper.text()).toContain('View Menu')
    })

    it('tracks menu click on click', async () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ menu_url: 'https://menu.example.com', id: 99 }),
      })
      const buttons = wrapper.findAll('button')
      const menuBtn = buttons.find(b => b.text() === 'View Menu')
      await menuBtn!.trigger('click')
      expect(mockTrackMenuClick).toHaveBeenCalledWith(99)
    })

    it('does not render menu button without menu_url', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ menu_url: null }) })
      expect(wrapper.text()).not.toContain('View Menu')
    })
  })

  describe('ratings', () => {
    it('shows ratings card when yelp_rating is present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ yelp_rating: 4.5, yelp_review_count: 120 }),
      })
      expect(wrapper.find('.star-rating-stub').exists()).toBe(true)
    })

    it('shows ratings card when google_rating is present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ google_rating: 4.2, google_review_count: 200 }),
      })
      expect(wrapper.find('.star-rating-stub').exists()).toBe(true)
    })

    it('hides ratings card when no ratings', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ yelp_rating: null, google_rating: null }),
      })
      expect(wrapper.find('.star-rating-stub').exists()).toBe(false)
    })
  })

  describe('directions', () => {
    it('renders directions link when lat/lng present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ lat: 40.7, lng: -74.0 }),
      })
      const dirLink = wrapper.findAll('a').find(el => el.text().includes('Get directions'))
      expect(dirLink).toBeTruthy()
    })

    it('calls trackDirections on click', async () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ lat: 40.7, lng: -74.0, id: 55 }),
      })
      const dirLink = wrapper.findAll('a').find(el => el.text().includes('Get directions'))
      await dirLink!.trigger('click')
      expect(mockTrackDirections).toHaveBeenCalledWith(55)
    })

    it('does not render directions when lat/lng absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ lat: null, lng: null }) })
      expect(wrapper.text()).not.toContain('Get directions')
    })
  })

  describe('social links', () => {
    it('renders SocialLinks when social_links is non-empty', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({
          social_links: [{ platform: 'instagram', url: 'https://insta.com/test', followers: 500 }],
        }),
      })
      expect(wrapper.find('.social-links-stub').exists()).toBe(true)
    })

    it('does not render SocialLinks when social_links is empty', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ social_links: [] }) })
      expect(wrapper.find('.social-links-stub').exists()).toBe(false)
    })
  })

  describe('opening hours', () => {
    it('renders OpeningHours when present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ opening_hours: { structured: true } }),
      })
      expect(wrapper.find('.opening-hours-stub').exists()).toBe(true)
    })

    it('does not render OpeningHours when absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ opening_hours: null }) })
      expect(wrapper.find('.opening-hours-stub').exists()).toBe(false)
    })
  })

  describe('score breakdown', () => {
    it('renders ScoreBreakdown when present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({
          score_breakdown: { signals: [], total: 85 },
        }),
      })
      expect(wrapper.find('.score-breakdown-stub').exists()).toBe(true)
      expect(wrapper.text()).toContain('Popularity Score')
    })

    it('does not render ScoreBreakdown when absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ score_breakdown: null }) })
      expect(wrapper.find('.score-breakdown-stub').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Popularity Score')
    })
  })

  describe('map', () => {
    it('renders DetailMap when lat/lng present', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ lat: 40.7, lng: -74.0, address: '123 St' }),
      })
      expect(wrapper.find('.detail-map-stub').exists()).toBe(true)
      expect(wrapper.text()).toContain('Location')
    })

    it('does not render map when lat/lng absent', () => {
      const wrapper = mountComponent({ restaurant: makeRestaurant({ lat: null, lng: null }) })
      expect(wrapper.find('.detail-map-stub').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('Location')
    })
  })

  describe('gallery', () => {
    it('renders CardGallery with photos', () => {
      const wrapper = mountComponent({
        restaurant: makeRestaurant({ photo_url: 'https://img.com/hero.jpg' }),
      })
      expect(wrapper.find('.card-gallery-stub').exists()).toBe(true)
    })
  })

  describe('favorites', () => {
    it('renders heart button', () => {
      const wrapper = mountComponent()
      const buttons = wrapper.findAll('button')
      const heartBtn = buttons.find(b => b.attributes('aria-label') === 'Save restaurant')
      expect(heartBtn).toBeTruthy()
    })

    it('shows filled heart when favorited', () => {
      isFavoritedMock.mockReturnValue(true)
      const wrapper = mountComponent()
      const buttons = wrapper.findAll('button')
      const heartBtn = buttons.find(b => b.attributes('aria-label') === 'Saved')
      expect(heartBtn).toBeTruthy()
      expect(heartBtn!.classes()).toContain('text-red-500')
    })

    it('calls toggle on heart click', async () => {
      const restaurant = makeRestaurant({ id: 99 })
      const wrapper = mountComponent({ restaurant })
      const buttons = wrapper.findAll('button')
      const heartBtn = buttons.find(b => b.attributes('aria-label') === 'Save restaurant')
      await heartBtn!.trigger('click')
      expect(toggleMock).toHaveBeenCalledWith(restaurant)
    })
  })

  describe('pageview tracking', () => {
    it('tracks pageview on mount', () => {
      mountComponent({ restaurant: makeRestaurant({ id: 42 }) })
      expect(mockTrackPageview).toHaveBeenCalledWith(42)
    })
  })

  describe('SEO and structured data', () => {
    it('renders SeoMeta and JsonLd stubs', () => {
      const wrapper = mountComponent()
      expect(wrapper.html()).toContain('seodata=')
      expect(wrapper.html()).toContain('<!-- Structured data')
    })
  })

  describe('isLivePreview', () => {
    it('can render with isLivePreview true', () => {
      const wrapper = mountComponent({ isLivePreview: true })
      expect(wrapper.find('h1').exists()).toBe(true)
    })
  })

  describe('canonicalUrl', () => {
    it('can render with custom canonicalUrl', () => {
      const wrapper = mountComponent({
        canonicalUrl: 'https://custom.example.com/restaurant/test',
      })
      expect(wrapper.find('h1').exists()).toBe(true)
    })
  })
})
