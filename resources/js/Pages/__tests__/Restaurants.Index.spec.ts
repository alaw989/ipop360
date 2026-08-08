import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import RestaurantsIndex from '@/Pages/Restaurants/Index.vue'

const { mockRouterGet, mockRouterOn, callbacks } = vi.hoisted(() => {
  return {
    mockRouterGet: vi.fn(),
    mockRouterOn: vi.fn(),
    callbacks: {} as Record<string, (() => void) | null>,
  }
})

vi.mock('@inertiajs/vue3', async () => {
  const actual = await vi.importActual('@inertiajs/vue3')
  return {
    ...actual,
    router: {
      get: mockRouterGet,
      on: (event: string, cb: () => void) => {
        callbacks[event] = cb
        return mockRouterOn(event, cb)
      },
    },
  }
})

vi.mock('@/composables/useSeo', () => ({
  useSeo: () => ({
    title: 'Test Title',
    description: 'Test Description',
    ogTitle: 'Test OG Title',
    ogDescription: 'Test OG Description',
    ogType: 'website',
    ogImage: 'https://example.com/image.jpg',
    ogUrl: 'http://localhost/test',
    twitterCard: 'summary_large_image',
  }),
  generateItemListJsonLd: vi.fn(() => ({ '@type': 'ItemList', itemListElement: [] })),
}))

vi.mock('@/composables/useBaseUrl', () => ({
  useBaseUrl: () => ({ value: 'http://localhost' }),
}))

function makeRestaurant(overrides: Record<string, unknown> = {}) {
  return {
    id: 1,
    name: 'Test Restaurant',
    slug: 'test-restaurant',
    address: '123 Test St',
    city: 'Test City',
    state: 'TS',
    zip: '12345',
    phone: '555-0100',
    website: null,
    menu_url: null,
    online_ordering: false,
    online_ordering_service: null,
    rating: null,
    review_count: null,
    price_level: null,
    price_range: null,
    cuisine_names: ['Italian'],
    cuisines: [{ id: 1, name: 'Italian', category_id: 1, category_slug: 'italian', slug: 'italian', image: null }],
    lat: 40.7128,
    lng: -74.006,
    has_website: false,
    has_menu: false,
    has_socials: false,
    social_links: [],
    business_status: 'OPERATIONAL',
    hours: null,
    images: [],
    logo_url: null,
    popularity_score: 50,
    popularity_rank: null,
    pageviews_count: 0,
    website_clicks: 0,
    clicks_total: 0,
    engagement_data: null,
    is_favorite: false,
    open_now: null,
    yelp_id: null,
    google_place_id: null,
    source_urls: null,
    score_breakdown: null as unknown,
    ...overrides,
  }
}

const stubs = {
  AppLayout: { template: '<div><slot /></div>' },
  SeoMeta: { template: '<div />' },
  JsonLd: { template: '<div />' },
  RestaurantCard: {
    props: ['restaurant', 'rank'],
    template: '<div class="restaurant-card-stub">{{ restaurant.name }} (rank {{ rank }})</div>',
  },
  RestaurantCardSkeleton: { template: '<div class="skeleton-stub" />' },
  Button: {
    props: ['as', 'href', 'variant', 'size'],
    template: '<a v-if="href" :href="href"><slot /></a><button v-else><slot /></button>',
  },
}

function defaultProps(overrides: Record<string, unknown> = {}) {
  return {
    filters: { cuisine: 'italian' },
    cuisineName: 'Italian',
    categorySlug: 'italian-cuisine',
    restaurants: {
      data: [makeRestaurant()],
      current_page: 1,
      last_page: 1,
      prev_page_url: null,
      next_page_url: null,
    },
    ...overrides,
  }
}

function mountComponent(propsOverrides: Record<string, unknown> = {}) {
  return mount(RestaurantsIndex, {
    props: defaultProps(propsOverrides),
    global: { stubs },
  })
}

beforeEach(() => {
  mockRouterGet.mockClear()
  mockRouterOn.mockClear()
  callbacks.start = null
  callbacks.finish = null
})

describe('Restaurants/Index', () => {
  describe('cuisine filtering', () => {
    it('renders heading with cuisine name', () => {
      const wrapper = mountComponent()
      expect(wrapper.text()).toContain('Top italian Restaurants')
    })

    it('renders "Top all Restaurants" heading when cuisineName is null', () => {
      const wrapper = mountComponent({ cuisineName: null })
      expect(wrapper.text()).toContain('Top all Restaurants')
    })

    it('shows back link to category slug page when categorySlug is set', () => {
      const wrapper = mountComponent({ categorySlug: 'italian-cuisine' })
      const backLink = wrapper.find('a[href="/cuisine/italian-cuisine"]')
      expect(backLink.exists()).toBe(true)
    })

    it('shows back link to home when no categorySlug', () => {
      const wrapper = mountComponent({ categorySlug: null })
      const backLink = wrapper.find('a[href="/"]')
      expect(backLink.exists()).toBe(true)
    })
  })

  describe('skeleton loading', () => {
    it('shows skeleton cards when isLoading is true', async () => {
      const wrapper = mountComponent()
      // Register the router listener, then trigger start
      callbacks.start?.()
      await wrapper.vm.$nextTick()
      const skeletons = wrapper.findAll('.skeleton-stub')
      expect(skeletons.length).toBe(8)
    })

    it('hides skeleton cards when isLoading is false', async () => {
      const wrapper = mountComponent()
      // Default: isLoading is false, so skeletons should not show
      const skeletons = wrapper.findAll('.skeleton-stub')
      expect(skeletons.length).toBe(0)
    })
  })

  describe('empty state', () => {
    it('shows empty message when no restaurants in data', () => {
      const wrapper = mountComponent({
        restaurants: {
          data: [],
          current_page: 1,
          last_page: 1,
          prev_page_url: null,
          next_page_url: null,
        },
      })
      expect(wrapper.text()).toContain('No Italian restaurants found')
    })

    it('does not show restaurant cards when empty', () => {
      const wrapper = mountComponent({
        restaurants: {
          data: [],
          current_page: 1,
          last_page: 1,
          prev_page_url: null,
          next_page_url: null,
        },
      })
      expect(wrapper.findAll('.restaurant-card-stub').length).toBe(0)
    })
  })

  describe('results rendering', () => {
    it('renders RestaurantCard for each restaurant', () => {
      const restaurants = [makeRestaurant({ id: 1, name: 'Restaurant A' }), makeRestaurant({ id: 2, name: 'Restaurant B' })]
      const wrapper = mountComponent({
        restaurants: {
          data: restaurants,
          current_page: 1,
          last_page: 1,
          prev_page_url: null,
          next_page_url: null,
        },
      })
      const cards = wrapper.findAll('.restaurant-card-stub')
      expect(cards.length).toBe(2)
      expect(cards[0].text()).toContain('Restaurant A')
      expect(cards[1].text()).toContain('Restaurant B')
    })

    it('computes rank correctly based on current_page', () => {
      const restaurants = [makeRestaurant({ id: 1, name: 'R1' }), makeRestaurant({ id: 2, name: 'R2' })]
      const wrapper = mountComponent({
        restaurants: {
          data: restaurants,
          current_page: 2,
          last_page: 3,
          prev_page_url: '/restaurants?page=1',
          next_page_url: '/restaurants?page=3',
        },
      })
      const cards = wrapper.findAll('.restaurant-card-stub')
      // current_page=2, so rank = (2-1)*20 + index + 1 => 21, 22
      expect(cards[0].text()).toContain('rank 21')
      expect(cards[1].text()).toContain('rank 22')
    })
  })

  describe('sort dropdown', () => {
    it('renders sort select with all options', () => {
      const wrapper = mountComponent()
      const select = wrapper.find('select')
      expect(select.exists()).toBe(true)
      const options = select.findAll('option')
      expect(options.length).toBe(5)
    })

    it('calls router.get on sort change', async () => {
      const wrapper = mountComponent()
      const select = wrapper.find('select')
      await select.setValue('nearest')
      expect(mockRouterGet).toHaveBeenCalledWith(
        '/restaurants',
        { cuisine: 'italian', sort: 'nearest' },
        { preserveState: true, replace: true },
      )
    })
  })

  describe('pagination', () => {
    it('does not show pagination when last_page is 1', () => {
      const wrapper = mountComponent()
      const paginationLinks = wrapper.findAll('a[href]').filter((el) => el.text() === 'Previous' || el.text() === 'Next')
      expect(paginationLinks.length).toBe(0)
    })

    it('shows pagination when last_page > 1', () => {
      const wrapper = mountComponent({
        restaurants: {
          data: [makeRestaurant()],
          current_page: 1,
          last_page: 3,
          prev_page_url: null,
          next_page_url: '/restaurants?page=2&cuisine=italian',
        },
      })
      expect(wrapper.text()).toMatch(/Page 1 of 3/)
      const nextButton = wrapper.findAll('a').find((el) => el.text().trim() === 'Next')
      expect(nextButton).toBeTruthy()
      expect(nextButton!.attributes('href')).toContain('page=2')
    })

    it('shows Previous button when prev_page_url is set', () => {
      const wrapper = mountComponent({
        restaurants: {
          data: [makeRestaurant()],
          current_page: 2,
          last_page: 3,
          prev_page_url: '/restaurants?page=1&cuisine=italian',
          next_page_url: '/restaurants?page=3&cuisine=italian',
        },
      })
      const prevButton = wrapper.findAll('a').find((el) => el.text().trim() === 'Previous')
      expect(prevButton).toBeTruthy()
      expect(prevButton!.attributes('href')).toContain('page=1')
    })
  })
})
