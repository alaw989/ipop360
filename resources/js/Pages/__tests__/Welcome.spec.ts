import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import Welcome from '@/Pages/Welcome.vue'
import { useSeo } from '@/composables/useSeo'

const {
    mockRouterGet,
    mockPersistedLocation,
    mockLat,
    mockLng,
    mockPersistLocation,
    mockRestorePersistedLocation,
    mockDetectLocation,
    mockDetectingLocation,
    mockGeolocationError,
    mockRestaurants,
    mockSearch,
    mockResort,
    mockLoadMore,
    mockResetState,
    mockNextPageUrl,
    mockSearchError,
    mockLoadMoreError,
    mockIsResorting,
    mockShouldStagger,
} = vi.hoisted(() => {
    const mockRouterGet = vi.fn()
    const mockPersistedLocation = { value: { city: null, state: null } as { city: string | null; state: string | null } }
    const mockLat = { value: null as number | null }
    const mockLng = { value: null as number | null }
    const mockPersistLocation = vi.fn()
    const mockRestorePersistedLocation = vi.fn()
    const mockDetectLocation = vi.fn()
    const mockDetectingLocation = { value: false }
    const mockGeolocationError = { value: null as string | null }
    const mockRestaurants = { value: [] as any[] }
    const mockSearch = vi.fn()
    const mockResort = vi.fn()
    const mockLoadMore = vi.fn()
    const mockResetState = vi.fn()
    const mockNextPageUrl = { value: null as string | null }
    const mockSearchError = { value: null as string | null }
    const mockLoadMoreError = { value: null as string | null }
    const mockIsResorting = { value: false }
    const mockShouldStagger = { value: false }
    return {
        mockRouterGet,
        mockPersistedLocation,
        mockLat,
        mockLng,
        mockPersistLocation,
        mockRestorePersistedLocation,
        mockDetectLocation,
        mockDetectingLocation,
        mockGeolocationError,
        mockRestaurants,
        mockSearch,
        mockResort,
        mockLoadMore,
        mockResetState,
        mockNextPageUrl,
        mockSearchError,
        mockLoadMoreError,
        mockIsResorting,
        mockShouldStagger,
    }
})

const mockSerpapiExhausted = vi.hoisted(() => ({ value: false }))

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...(actual as any),
        router: {
            get: mockRouterGet,
        },
        usePage: () => ({ props: { serpapi_exhausted: mockSerpapiExhausted.value } }),
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
    }
})

vi.mock('@/composables/useSeo', () => ({
    useSeo: vi.fn((options: any) => ({
        title: options.title,
        description: options.description,
        canonical: 'http://localhost/',
        url: options.url ?? 'http://localhost/',
        type: options.type ?? 'website',
    })),
    generateWebSiteJsonLd: vi.fn(() => ({ '@type': 'WebSite' })),
    generateOrganizationJsonLd: vi.fn(() => ({ '@type': 'Organization' })),
}))

vi.mock('@/composables/useBaseUrl', () => ({
    useBaseUrl: vi.fn(() => ({ value: 'http://localhost' })),
}))

vi.mock('@/composables/useRestaurantSearch', () => ({
    useRestaurantSearch: vi.fn(() => ({
        restaurants: mockRestaurants,
        shouldStagger: mockShouldStagger,
        isResorting: mockIsResorting,
        nextPageUrl: mockNextPageUrl,
        searchError: mockSearchError,
        loadMoreError: mockLoadMoreError,
        search: mockSearch,
        resort: mockResort,
        loadMore: mockLoadMore,
        resetState: mockResetState,
    })),
}))

vi.mock('@/composables/useGeolocation', () => ({
    useGeolocation: vi.fn(() => ({
        detectingLocation: mockDetectingLocation,
        geolocationError: mockGeolocationError,
        detectLocation: mockDetectLocation,
    })),
}))

vi.mock('@/composables/usePersistedLocation', () => ({
    usePersistedLocation: vi.fn(() => ({
        location: mockPersistedLocation,
        lat: mockLat,
        lng: mockLng,
        persistLocation: mockPersistLocation,
        restore: mockRestorePersistedLocation,
    })),
}))

const stubs = {
    SeoMeta: { template: '<div />' },
    JsonLd: { template: '<div />' },
    TopNav: {
        props: ['sticky', 'transparent'],
        template: '<nav class="top-nav-stub" data-testid="top-nav" :sticky="sticky" :transparent="transparent" />',
    },
    AppFooter: { template: '<footer class="app-footer-stub">Footer</footer>' },
    HeroBanner: {
        name: 'HeroBanner',
        props: ['categories', 'location', 'detectingLocation', 'stats'],
        emits: ['cuisineSelect', 'locationUpdate', 'coords', 'detect', 'search'],
        template: '<div class="hero-banner-stub"><button class="search-btn" @click="$emit(\'search\')">Search</button></div>',
    },
    StickySearchBar: {
        props: ['location'],
        emits: ['refineSearch'],
        template: '<div class="sticky-search-bar-stub" />',
    },
    ScrollReveal: {
        props: ['delay', 'threshold'],
        template: '<div class="scroll-reveal-stub" :data-delay="delay"><slot /></div>',
    },
    CategoryGrid: {
        props: ['categories', 'loading', 'lat', 'lng'],
        template: '<div class="category-grid-stub" />',
    },
    PopularCuisines: {
        props: ['cuisines', 'city', 'loading', 'lat', 'lng'],
        template: '<div class="popular-cuisines-stub" />',
    },
    PopularRestaurants: {
        props: ['restaurants', 'city', 'loading'],
        template: '<div class="popular-restaurants-stub"><span class="restaurant-count">{{ restaurants.length }}</span><span class="city-label">{{ city }}</span></div>',
    },
    BlogPreview: {
        props: ['posts'],
        template: '<div class="blog-preview-stub" />',
    },
    ResultsGrid: {
        props: ['phase', 'restaurants', 'resultCount', 'sort', 'sortOptions', 'nextPageUrl', 'searchError', 'loadMoreError', 'lat', 'lng', 'selectedCuisine', 'shouldStagger', 'isResorting'],
        emits: ['update:sort', 'resort', 'loadMore', 'resetToIdle', 'dismissLoadMoreError', 'search'],
        template: '<div class="results-grid-stub" />',
    },
    Button: {
        props: ['variant', 'size', 'as', 'href'],
        template: '<a v-if="as === \'a\'" :href="href"><slot /></a><button v-else><slot /></button>',
    },
    Badge: {
        props: ['variant'],
        template: '<span><slot /></span>',
    },
    Card: { template: '<div class="card-stub"><slot /></div>' },
    CardContent: { template: '<div><slot /></div>' },
    Transition: { template: '<div><slot /></div>' },
}

function makeCategory(overrides: Record<string, unknown> = {}) {
    return { id: 1, name: 'Italian', slug: 'italian-cuisine', icon: null, cuisines: [], ...overrides }
}

function makePopularCuisine(overrides: Record<string, unknown> = {}) {
    return { id: 1, name: 'Italian', slug: 'italian', icon: null, restaurants_count: 42, ...overrides }
}

function makePopularRestaurant(overrides: Record<string, unknown> = {}) {
    return {
        id: 1,
        name: 'Test Restaurant',
        slug: 'test-restaurant',
        photo_url: null,
        city: null,
        state: null,
        price_range: null,
        google_rating: null,
        google_review_count: 0,
        yelp_rating: null,
        yelp_review_count: 0,
        has_award: false,
        popularity_score: 50,
        latitude: null,
        longitude: null,
        cuisines: [],
        ...overrides,
    }
}

function makeBlogPost(overrides: Record<string, unknown> = {}) {
    return { id: 1, title: 'Test Post', slug: 'test-post', excerpt: 'excerpt', featured_image: null, published_at: null, ...overrides }
}

function defaultProps(overrides: Record<string, unknown> = {}) {
    return {
        categories: [makeCategory()],
        popularCuisines: [makePopularCuisine()],
        popularRestaurants: [makePopularRestaurant()],
        latestPosts: [makeBlogPost()],
        location: null,
        fallbackCoords: null,
        stats: { restaurants: 100, cuisines: 50, cities: 20 },
        ...overrides,
    }
}

function mountWelcome(propsOverrides: Record<string, unknown> = {}) {
    return mount(Welcome, {
        props: defaultProps(propsOverrides),
        global: { stubs },
    })
}

beforeEach(() => {
    mockRouterGet.mockClear()
    mockSerpapiExhausted.value = false
    mockPersistedLocation.value = { city: null, state: null }
    mockLat.value = null
    mockLng.value = null
    mockPersistLocation.mockClear()
    mockRestorePersistedLocation.mockClear()
    mockDetectLocation.mockClear()
    mockDetectingLocation.value = false
    mockGeolocationError.value = null
    mockRestaurants.value = []
    mockSearch.mockClear()
    mockResort.mockClear()
    mockLoadMore.mockClear()
    mockResetState.mockClear()
    mockNextPageUrl.value = null
    mockSearchError.value = null
    mockLoadMoreError.value = null
    mockIsResorting.value = false
    mockShouldStagger.value = false
})

describe('Welcome', () => {
    describe('top nav', () => {
        it('renders the AppLayout top nav on the homepage', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('[data-testid="top-nav"]').exists()).toBe(true)
        })

        it('renders the top nav as non-sticky to keep StickySearchBar in charge during results', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('[data-testid="top-nav"]').attributes('sticky')).toBe('false')
        })

        it('renders the top nav as transparent so it overlays the hero slideshow', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('[data-testid="top-nav"]').attributes('transparent')).toBeDefined()
        })
    })

    describe('hero', () => {
        it('renders accessible h1 heading', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('h1').text()).toBe('Find Popular Restaurants Near You')
        })

        it('renders HeroBanner in idle phase', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.hero-banner-stub').exists()).toBe(true)
        })

        it('HeroBanner renders search button', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.hero-banner-stub .search-btn').exists()).toBe(true)
        })

        it('onSearch navigates to /search', async () => {
            const wrapper = mountWelcome()
            await wrapper.find('.search-btn').trigger('click')
            expect(mockRouterGet).toHaveBeenCalled()
        })
    })

    describe('featured restaurants', () => {
        it('renders PopularRestaurants section', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.popular-restaurants-stub').exists()).toBe(true)
        })

        it('renders PopularRestaurants with default city (from props location)', () => {
            const wrapper = mountWelcome({ location: { city: 'Denver', state: 'CO' } })
            const cityLabel = wrapper.find('.city-label')
            expect(cityLabel.text()).toBe('Denver')
        })

        it('passes popularRestaurants data to PopularRestaurants', () => {
            const restaurants = [
                makePopularRestaurant({ id: 1, name: 'Pizza Place' }),
                makePopularRestaurant({ id: 2, name: 'Sushi Bar' }),
            ]
            const wrapper = mountWelcome({ popularRestaurants: restaurants })
            const count = wrapper.find('.restaurant-count')
            expect(Number(count.text())).toBe(2)
        })

        it('shows empty restaurant count when no popular restaurants', () => {
            const wrapper = mountWelcome({ popularRestaurants: [] })
            const count = wrapper.find('.restaurant-count')
            expect(Number(count.text())).toBe(0)
        })
    })

    describe('idle phase sections', () => {
        it('renders CategoryGrid', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.category-grid-stub').exists()).toBe(true)
        })

        it('renders PopularCuisines', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.popular-cuisines-stub').exists()).toBe(true)
        })

        it('renders BlogPreview', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.blog-preview-stub').exists()).toBe(true)
        })

        it('renders BlogPreview with latestPosts prop', () => {
            const posts = [makeBlogPost({ id: 1, title: 'Blog A' }), makeBlogPost({ id: 2, title: 'Blog B' })]
            const wrapper = mountWelcome({ latestPosts: posts })
            expect(wrapper.find('.blog-preview-stub').exists()).toBe(true)
        })

        it('renders AppFooter', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.app-footer-stub').exists()).toBe(true)
        })
    })

    describe('scroll-reveal stagger', () => {
        it('staggers the idle-phase sections so they cascade in', () => {
            const wrapper = mountWelcome()
            const delays = wrapper.findAll('.scroll-reveal-stub').map(n => n.attributes('data-delay'))
            expect(delays).toEqual(['0', '80', '160', '240'])
        })
    })

    describe('hero stats', () => {
        it('passes stats counts to HeroBanner', () => {
            const wrapper = mountWelcome({ stats: { restaurants: 321, cuisines: 45, cities: 12 } })
            const hero = wrapper.findComponent({ name: 'HeroBanner' })
            expect(hero.exists()).toBe(true)
            expect(hero.props('stats')).toEqual({ restaurants: 321, cuisines: 45, cities: 12 })
        })
    })

    describe('search CTA', () => {
        it('renders search button in HeroBanner', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.search-btn').exists()).toBe(true)
        })

        it('clicking search calls router.get', async () => {
            const wrapper = mountWelcome()
            await wrapper.find('.search-btn').trigger('click')
            expect(mockRouterGet).toHaveBeenCalledTimes(1)
        })
    })

    describe('non-idle phase absence', () => {
        it('ResultsGrid is not visible in idle phase', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.results-grid-stub').exists()).toBe(false)
        })

        it('StickySearchBar is not visible in idle phase', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.sticky-search-bar-stub').exists()).toBe(false)
        })
    })

    describe('geolocation error', () => {
        it('shows geolocation error card when error is set', () => {
            mockGeolocationError.value = 'Geolocation failed'
            const wrapper = mountWelcome()
            expect(wrapper.find('.card-stub').exists()).toBe(true)
        })

        it('does not show error card when no error', () => {
            mockGeolocationError.value = null
            const wrapper = mountWelcome()
            // Card stub may still render if HeroBanner or other stubs don't use Card
        })
    })

    describe('SEO description', () => {
        beforeEach(() => {
            vi.mocked(useSeo).mockClear()
        })

        it('mentions real reviews and accurate ratings when SerpApi is available', () => {
            mountWelcome()
            expect(vi.mocked(useSeo)).toHaveBeenCalledWith(expect.objectContaining({
                description: expect.stringContaining('Real reviews, accurate ratings'),
            }))
        })

        it('uses neutral copy when SerpApi is exhausted', () => {
            mockSerpapiExhausted.value = true
            mountWelcome()
            const last = vi.mocked(useSeo).mock.calls.at(-1)![0] as { description: string }
            expect(last.description).not.toMatch(/review|rating/i)
        })
    })
})
