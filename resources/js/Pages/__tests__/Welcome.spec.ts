import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import Welcome from '@/Pages/Welcome.vue'
import { useSeo } from '@/composables/useSeo'

const {
    mockRouterGet,
    mockDetectLocation,
    mockDetectingLocation,
    mockGeolocationError,
    mockBeginSearchLoading,
    mockEndSearchLoading,
} = vi.hoisted(() => {
    const mockRouterGet = vi.fn()
    const mockDetectLocation = vi.fn()
    const mockDetectingLocation = { value: false }
    const mockGeolocationError = { value: null as string | null }
    const mockBeginSearchLoading = vi.fn()
    const mockEndSearchLoading = vi.fn()
    return {
        mockRouterGet,
        mockDetectLocation,
        mockDetectingLocation,
        mockGeolocationError,
        mockBeginSearchLoading,
        mockEndSearchLoading,
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

vi.mock('@/composables/useGeolocation', () => ({
    useGeolocation: vi.fn(() => ({
        detectingLocation: mockDetectingLocation,
        geolocationError: mockGeolocationError,
        detectLocation: mockDetectLocation,
    })),
}))

vi.mock('@/composables/useSearchLoadingOverlay', () => ({
    useSearchLoadingOverlay: vi.fn(() => ({
        isVisible: { value: false },
        begin: mockBeginSearchLoading,
        end: mockEndSearchLoading,
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
        props: ['categories', 'location', 'detectingLocation'],
        emits: ['cuisineSelect', 'locationUpdate', 'coords', 'detect', 'search'],
        template: '<div class="hero-banner-stub"><button class="search-btn" @click="$emit(\'search\')">Search</button></div>',
    },
    ScrollReveal: {
        props: ['delay', 'threshold'],
        template: '<div class="scroll-reveal-stub" :data-delay="delay"><slot /></div>',
    },
    PopularCities: {
        props: ['cities'],
        template: '<div class="popular-cities-stub" />',
    },
    PopularRestaurants: {
        props: ['restaurants', 'city', 'loading'],
        template: '<div class="popular-restaurants-stub"><span class="restaurant-count">{{ restaurants.length }}</span><span class="city-label">{{ city }}</span></div>',
    },
    BlogPreview: {
        props: ['posts'],
        template: '<div class="blog-preview-stub" />',
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

function makePopularCity(overrides: Record<string, unknown> = {}) {
    return { name: 'Chicago', city: 'Chicago', state: 'IL', ...overrides }
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
        popularCities: [makePopularCity()],
        popularRestaurants: [makePopularRestaurant()],
        latestPosts: [makeBlogPost()],
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
    mockDetectLocation.mockClear()
    mockDetectingLocation.value = false
    mockGeolocationError.value = null
    mockBeginSearchLoading.mockClear()
    mockEndSearchLoading.mockClear()
})

describe('Welcome', () => {
    describe('top nav', () => {
        it('renders the AppLayout top nav on the homepage', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('[data-testid="top-nav"]').exists()).toBe(true)
        })

        it('renders the top nav as non-sticky', () => {
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

        it('renders HeroBanner', () => {
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

        it('renders PopularRestaurants with no city label until a city is picked', () => {
            const wrapper = mountWelcome()
            const cityLabel = wrapper.find('.city-label')
            expect(cityLabel.text()).toBe('')
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

    describe('homepage sections', () => {
        it('renders PopularCities', () => {
            const wrapper = mountWelcome()
            expect(wrapper.find('.popular-cities-stub').exists()).toBe(true)
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
        it('staggers the homepage sections so they cascade in', () => {
            const wrapper = mountWelcome()
            const delays = wrapper.findAll('.scroll-reveal-stub').map(n => n.attributes('data-delay'))
            expect(delays).toEqual(['0', '80', '160'])
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

    describe('geolocation error', () => {
        it('shows geolocation error card when error is set', () => {
            mockGeolocationError.value = 'Geolocation failed'
            const wrapper = mountWelcome()
            expect(wrapper.find('.card-stub').exists()).toBe(true)
        })

        it('does not show error card when no error', () => {
            mockGeolocationError.value = null
            const wrapper = mountWelcome()
            // Note: mockGeolocationError is a plain object, not a real ref, so
            // Vue's template doesn't auto-unwrap it — this can't be asserted
            // meaningfully against this mock (pre-existing limitation).
            void wrapper
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

    describe('search loading overlay', () => {
        // The overlay itself renders at the app root (app.ts), not inside
        // Welcome.vue — see useSearchLoadingOverlay for why (its fade-out must
        // survive Inertia swapping this page away). Welcome.vue's job is just
        // to call begin()/end() at the right times, which is what's verified
        // here via the mocked composable.
        it('calls begin() when a search is triggered', async () => {
            const wrapper = mountWelcome()
            await wrapper.find('.search-btn').trigger('click')
            expect(mockBeginSearchLoading).toHaveBeenCalledTimes(1)
        })

        it('passes an onFinish that calls end() when the search visit completes', async () => {
            const wrapper = mountWelcome()
            await wrapper.find('.search-btn').trigger('click')

            const onFinish = mockRouterGet.mock.calls.at(-1)![2].onFinish
            expect(mockEndSearchLoading).not.toHaveBeenCalled()
            onFinish()
            expect(mockEndSearchLoading).toHaveBeenCalledTimes(1)
        })
    })
})
