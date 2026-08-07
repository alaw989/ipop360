import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { computed } from 'vue'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
}))

import SeoMeta from '@/Components/SeoMeta.vue'

const seoData = {
    title: 'Best Pizza in NYC',
    description: 'Find the best pizza places in New York City.',
    canonical: 'https://ipop360.com/search/pizza-nyc',
    ogTitle: 'Best Pizza in NYC — iPop360',
    ogDescription: 'Discover top-rated pizza restaurants in NYC.',
    ogType: 'website',
    ogUrl: 'https://ipop360.com/search/pizza-nyc',
    ogSiteName: 'iPop360',
    ogImage: 'https://ipop360.com/og/pizza.png',
    ogImageAlt: 'A delicious pizza slice',
    twitterCard: 'summary_large_image',
    twitterTitle: 'Best Pizza in NYC',
    twitterDescription: 'Top pizza spots in New York.',
    twitterImage: 'https://ipop360.com/twitter/pizza.png',
}

function mountSeoMeta(data: Record<string, unknown>) {
    return mount(SeoMeta, { props: { seoData: data } })
}

describe('SeoMeta', () => {
    it('renders the title', () => {
        const wrapper = mountSeoMeta(seoData)
        expect(wrapper.find('title').text()).toBe('Best Pizza in NYC')
    })

    it('renders the meta description', () => {
        const wrapper = mountSeoMeta(seoData)
        const meta = wrapper.find('meta[name="description"]')
        expect(meta.attributes('content')).toBe('Find the best pizza places in New York City.')
    })

    it('renders the canonical link', () => {
        const wrapper = mountSeoMeta(seoData)
        const link = wrapper.find('link[rel="canonical"]')
        expect(link.attributes('href')).toBe('https://ipop360.com/search/pizza-nyc')
    })

    it('renders all og meta tags', () => {
        const wrapper = mountSeoMeta(seoData)
        expect(wrapper.find('meta[property="og:title"]').attributes('content')).toBe('Best Pizza in NYC — iPop360')
        expect(wrapper.find('meta[property="og:description"]').attributes('content')).toBe('Discover top-rated pizza restaurants in NYC.')
        expect(wrapper.find('meta[property="og:type"]').attributes('content')).toBe('website')
        expect(wrapper.find('meta[property="og:url"]').attributes('content')).toBe('https://ipop360.com/search/pizza-nyc')
        expect(wrapper.find('meta[property="og:site_name"]').attributes('content')).toBe('iPop360')
        expect(wrapper.find('meta[property="og:image"]').attributes('content')).toBe('https://ipop360.com/og/pizza.png')
        expect(wrapper.find('meta[property="og:image:alt"]').attributes('content')).toBe('A delicious pizza slice')
    })

    it('renders all twitter meta tags', () => {
        const wrapper = mountSeoMeta(seoData)
        expect(wrapper.find('meta[name="twitter:card"]').attributes('content')).toBe('summary_large_image')
        expect(wrapper.find('meta[name="twitter:title"]').attributes('content')).toBe('Best Pizza in NYC')
        expect(wrapper.find('meta[name="twitter:description"]').attributes('content')).toBe('Top pizza spots in New York.')
        expect(wrapper.find('meta[name="twitter:image"]').attributes('content')).toBe('https://ipop360.com/twitter/pizza.png')
    })

    it('renders noindex meta when noindex is true', () => {
        const wrapper = mountSeoMeta({ ...seoData, noindex: true })
        const meta = wrapper.find('meta[name="robots"]')
        expect(meta.exists()).toBe(true)
        expect(meta.attributes('content')).toBe('noindex, nofollow')
    })

    it('does not render robots meta when noindex is false', () => {
        const wrapper = mountSeoMeta({ ...seoData, noindex: false })
        expect(wrapper.find('meta[name="robots"]').exists()).toBe(false)
    })

    it('does not render robots meta when noindex is absent', () => {
        const wrapper = mountSeoMeta(seoData)
        expect(wrapper.find('meta[name="robots"]').exists()).toBe(false)
    })

    it('accepts a ComputedRef as the seoData prop', () => {
        const computedSeo = computed(() => seoData)
        const wrapper = mount(SeoMeta, { props: { seoData: computedSeo } })
        expect(wrapper.find('title').text()).toBe('Best Pizza in NYC')
        expect(wrapper.find('meta[name="description"]').attributes('content')).toBe('Find the best pizza places in New York City.')
    })

    it('accepts a plain object as the seoData prop', () => {
        const wrapper = mountSeoMeta(seoData)
        expect(wrapper.find('title').text()).toBe('Best Pizza in NYC')
    })
})
