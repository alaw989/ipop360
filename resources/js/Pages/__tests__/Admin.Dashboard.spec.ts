import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import AdminDashboard from '@/Pages/Admin/Dashboard.vue'

const mockRoute = vi.fn((name: string) => {
    const routes: Record<string, string> = {
        'admin.blog.index': '/admin/blog',
    }
    return routes[name] ?? '#'
})

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Head: { template: '<div />' },
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
    }
})

vi.mock('@lucide/vue', () => ({
    AlertCircle: { template: '<svg data-testid="alert-circle" />' },
    CheckCircle2: { template: '<svg data-testid="check-circle-2" />' },
    Clock: { template: '<svg data-testid="clock" />' },
    Globe: { template: '<svg data-testid="globe" />' },
    Image: { template: '<svg data-testid="image" />' },
    Loader2: { template: '<svg data-testid="loader-2" />' },
    Newspaper: { template: '<svg data-testid="newspaper" />' },
    Share2: { template: '<svg data-testid="share-2" />' },
    Utensils: { template: '<svg data-testid="utensils" />' },
}))

const stubs = {
    AuthenticatedLayout: { template: '<div><slot name="header" /><slot /></div>' },
    Button: { template: '<button :disabled="disabled"><slot /></button>', props: ['disabled', 'variant', 'size', 'asChild'] },
    Card: { template: '<div><slot /></div>' },
    CardContent: { template: '<div><slot /></div>' },
    CardHeader: { template: '<div><slot /></div>' },
    CardTitle: { template: '<div><slot /></div>' },
    Badge: { template: '<span :class="variant"><slot /></span>', props: ['variant'] },
}

interface SerpApiQuota {
    calls_used: number
    free_quota: number
    remaining: number
    pct_used: number
    circuit_breaker_threshold: number
    circuit_breaker_tripped: boolean
    enrich_budget: number
    enrich_budget_exhausted: boolean
}

interface ScrapeHealth {
    last_social_scrape: string | null
    hours_since_social_scrape: number | null
    total_social_links: number
}

interface DataQuality {
    total_restaurants: number
    with_website: number
    with_website_pct: number
    with_social_links: number
    with_social_links_pct: number
    with_opening_hours: number
    with_opening_hours_pct: number
    with_photo: number
    with_photo_pct: number
    missing_data: {
        id: number
        name: string
        slug: string
        gaps: string[]
        gap_count: number
    }[]
}

function makeQuota(overrides: Partial<SerpApiQuota> = {}): SerpApiQuota {
    return {
        calls_used: 45,
        free_quota: 250,
        remaining: 205,
        pct_used: 18,
        circuit_breaker_threshold: 200,
        circuit_breaker_tripped: false,
        enrich_budget: 100,
        enrich_budget_exhausted: false,
        ...overrides,
    }
}

function makeScrapeHealth(overrides: Partial<ScrapeHealth> = {}): ScrapeHealth {
    return {
        last_social_scrape: '2025-06-15 02:00:00',
        hours_since_social_scrape: 3,
        total_social_links: 1420,
        ...overrides,
    }
}

function makeDataQuality(overrides: Partial<DataQuality> = {}): DataQuality {
    return {
        total_restaurants: 5500,
        with_website: 4100,
        with_website_pct: 74,
        with_social_links: 3200,
        with_social_links_pct: 58,
        with_opening_hours: 1800,
        with_opening_hours_pct: 32,
        with_photo: 2800,
        with_photo_pct: 50,
        missing_data: [],
        ...overrides,
    }
}

function mountComponent(propsOverrides: Record<string, any> = {}) {
    return mount(AdminDashboard, {
        props: {
            serpapiQuota: makeQuota(),
            scrapeHealth: makeScrapeHealth(),
            dataQuality: makeDataQuality(),
            ...propsOverrides,
        },
        global: {
            stubs,
            config: {
                globalProperties: {
                    route: mockRoute,
                },
            },
        },
    })
}

describe('Admin Dashboard page', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    it('renders the page heading', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Admin Dashboard')
    })

    it('renders a Manage Blog link', () => {
        const wrapper = mountComponent()
        const link = wrapper.find('a[href="/admin/blog"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toContain('Manage Blog')
    })

    it('renders SerpApi Quota section heading', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('SerpApi Quota')
    })

    it('displays usage as calls_used / free_quota', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ calls_used: 50, free_quota: 250 }),
        })
        expect(wrapper.text()).toContain('50 / 250')
    })

    it('displays remaining calls and percentage', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ remaining: 200, pct_used: 20 }),
        })
        expect(wrapper.text()).toContain('200 remaining')
        expect(wrapper.text()).toContain('(20%)')
    })

    it('shows Circuit Breaker as Open when not tripped', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ circuit_breaker_tripped: false }),
        })
        expect(wrapper.text()).toContain('Open')
        expect(wrapper.text()).not.toContain('Tripped')
    })

    it('shows Circuit Breaker as Tripped when tripped', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ circuit_breaker_tripped: true }),
        })
        expect(wrapper.text()).toContain('Tripped')
    })

    it('displays circuit breaker threshold', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ circuit_breaker_threshold: 200 }),
        })
        expect(wrapper.text()).toContain('Threshold: 200 calls')
    })

    it('shows Enrich Budget as Available when not exhausted', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ enrich_budget_exhausted: false }),
        })
        expect(wrapper.text()).toContain('Available')
        expect(wrapper.text()).not.toContain('Exhausted')
    })

    it('shows Enrich Budget as Exhausted when exhausted', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ enrich_budget_exhausted: true }),
        })
        expect(wrapper.text()).toContain('Exhausted')
    })

    it('displays enrich budget amount', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ enrich_budget: 100 }),
        })
        expect(wrapper.text()).toContain('Budget: 100 / month')
    })

    it('shows Live Read Path as Live when not tripped', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ circuit_breaker_tripped: false }),
        })
        expect(wrapper.text()).toContain('Live')
    })

    it('shows Live Read Path as Cache only when tripped', () => {
        const wrapper = mountComponent({
            serpapiQuota: makeQuota({ circuit_breaker_tripped: true }),
        })
        expect(wrapper.text()).toContain('Cache only')
    })

    it('renders Scrape Health section heading', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Scrape Health')
    })

    it('displays last social scrape timestamp', () => {
        const wrapper = mountComponent({
            scrapeHealth: makeScrapeHealth({ last_social_scrape: '2025-06-15 02:00:00' }),
        })
        expect(wrapper.text()).toContain('2025-06-15 02:00:00')
    })

    it('displays Never when last social scrape is null', () => {
        const wrapper = mountComponent({
            scrapeHealth: makeScrapeHealth({ last_social_scrape: null }),
        })
        expect(wrapper.text()).toContain('Never')
    })

    it('displays hours since social scrape', () => {
        const wrapper = mountComponent({
            scrapeHealth: makeScrapeHealth({ hours_since_social_scrape: 5 }),
        })
        expect(wrapper.text()).toContain('5 hours ago')
    })

    it('does not display hours-ago when hours_since is null', () => {
        const wrapper = mountComponent({
            scrapeHealth: makeScrapeHealth({ hours_since_social_scrape: null }),
        })
        expect(wrapper.text()).not.toContain('hours ago')
    })

    it('displays total social links count', () => {
        const wrapper = mountComponent({
            scrapeHealth: makeScrapeHealth({ total_social_links: 1420 }),
        })
        expect(wrapper.text()).toContain('1420')
    })

    it('renders Data Quality section heading', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Data Quality')
    })

    it('displays total restaurants count', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({ total_restaurants: 5500 }),
        })
        expect(wrapper.text()).toContain('5500')
    })

    it('displays with_website count and percentage', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({ with_website: 4100, with_website_pct: 74 }),
        })
        expect(wrapper.text()).toContain('4100')
        expect(wrapper.text()).toContain('74%')
    })

    it('displays with_social_links count and percentage', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({ with_social_links: 3200, with_social_links_pct: 58 }),
        })
        expect(wrapper.text()).toContain('3200')
        expect(wrapper.text()).toContain('58%')
    })

    it('displays with_opening_hours count and percentage', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({ with_opening_hours: 1800, with_opening_hours_pct: 32 }),
        })
        expect(wrapper.text()).toContain('1800')
        expect(wrapper.text()).toContain('32%')
    })

    it('displays with_photo count and percentage', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({ with_photo: 2800, with_photo_pct: 50 }),
        })
        expect(wrapper.text()).toContain('2800')
        expect(wrapper.text()).toContain('50%')
    })

    it('renders Missing Data section heading', () => {
        const wrapper = mountComponent()
        expect(wrapper.text()).toContain('Restaurants Missing Data')
    })

    it('shows empty state message when no missing data', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({ missing_data: [] }),
        })
        expect(wrapper.text()).toContain('All restaurants have complete data!')
    })

    it('renders missing data restaurant names as links', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({
                missing_data: [
                    { id: 1, name: 'Test Restaurant', slug: 'test-restaurant', gaps: ['website'], gap_count: 1 },
                ],
            }),
        })
        const link = wrapper.find('a[href="/restaurants/test-restaurant"]')
        expect(link.exists()).toBe(true)
        expect(link.text()).toBe('Test Restaurant')
    })

    it('renders gap badges for each missing data restaurant', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({
                missing_data: [
                    { id: 1, name: 'Test Restaurant', slug: 'test-restaurant', gaps: ['website', 'photo'], gap_count: 2 },
                ],
            }),
        })
        const badges = wrapper.findAllComponents(stubs.Badge)
        const badgeTexts = badges.map(b => b.text())
        expect(badgeTexts).toContain('website')
        expect(badgeTexts).toContain('photo')
    })

    it('assigns destructive variant to website gap badge', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({
                missing_data: [
                    { id: 1, name: 'Test Restaurant', slug: 'test-restaurant', gaps: ['website'], gap_count: 1 },
                ],
            }),
        })
        const badges = wrapper.findAllComponents(stubs.Badge)
        expect(badges[0].props('variant')).toBe('destructive')
    })

    it('assigns secondary variant to hours gap badge', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({
                missing_data: [
                    { id: 1, name: 'Test Restaurant', slug: 'test-restaurant', gaps: ['hours'], gap_count: 1 },
                ],
            }),
        })
        const badges = wrapper.findAllComponents(stubs.Badge)
        expect(badges[0].props('variant')).toBe('secondary')
    })

    it('assigns outline variant to photo gap badge', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({
                missing_data: [
                    { id: 1, name: 'Test Restaurant', slug: 'test-restaurant', gaps: ['photo'], gap_count: 1 },
                ],
            }),
        })
        const badges = wrapper.findAllComponents(stubs.Badge)
        expect(badges[0].props('variant')).toBe('outline')
    })

    it('renders multiple missing data rows', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({
                missing_data: [
                    { id: 1, name: 'Restaurant A', slug: 'restaurant-a', gaps: ['website'], gap_count: 1 },
                    { id: 2, name: 'Restaurant B', slug: 'restaurant-b', gaps: ['photo'], gap_count: 1 },
                ],
            }),
        })
        const rows = wrapper.findAll('tbody tr')
        expect(rows).toHaveLength(2)
        expect(wrapper.text()).toContain('Restaurant A')
        expect(wrapper.text()).toContain('Restaurant B')
    })

    it('shows wrong gap variant defaults to secondary', () => {
        const wrapper = mountComponent({
            dataQuality: makeDataQuality({
                missing_data: [
                    { id: 1, name: 'Test', slug: 'test', gaps: ['unknown_field'], gap_count: 1 },
                ],
            }),
        })
        const badges = wrapper.findAllComponents(stubs.Badge)
        expect(badges[0].props('variant')).toBe('secondary')
    })
})
