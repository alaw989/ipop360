import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import FeaturedRestaurant from '@/Components/FeaturedRestaurant.vue'

vi.mock('@inertiajs/vue3', () => ({
    Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
}))

function spotlight(overrides: Record<string, unknown> = {}) {
    return {
        id: 8629,
        name: "Moose's Tooth Pub & Pizzeria",
        slug: 'mooses-tooth',
        city: 'Anchorage',
        state: 'AK',
        price_range: '$$',
        google_rating: 4.7,
        google_review_count: 12072,
        cuisines: [{ id: 1, name: 'Pizza', slug: 'pizza' }],
        image: 'https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose.jpg/1280px-Moose.jpg',
        image_credit: 'Photo: LittleT889, CC BY-SA 4.0, via Wikimedia Commons',
        quote: 'Two rock climbers opened it in 1996.',
        story: { title: 'The story', slug: 'mooses-tooth-story' },
        picked: true,
        ...overrides,
    }
}

describe('FeaturedRestaurant', () => {
    it('is headed "Featured restaurant" for an admin pick', () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight() } })
        expect(wrapper.get('h2').text()).toBe('Featured restaurant')
    })

    it('says "Top-ranked near you" when nobody picked one', () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight({ picked: false }) } })
        expect(wrapper.get('h2').text()).toBe('Top-ranked near you')
    })

    it('shows the facts beside the photo, never on it', () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight() } })
        expect(wrapper.get('h3').text()).toBe("Moose's Tooth Pub & Pizzeria")
        expect(wrapper.text()).toContain('12,072 Google reviews')
        expect(wrapper.text()).toContain('Pizza')
        expect(wrapper.text()).toContain('Anchorage, AK')
        expect(wrapper.get('[data-testid="spotlight-photo"]').element.parentElement?.textContent?.trim()).toBe('')
    })

    it('links the restaurant, and the story when there is one', () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight() } })
        expect(wrapper.find('a[href="/restaurants/mooses-tooth"]').text()).toContain("Moose's Tooth")
        expect(wrapper.findAll('a').some((a) => a.text() === 'See the restaurant')).toBe(true)
        expect(wrapper.get('[data-testid="spotlight-story"]').attributes('href')).toBe('/blog/mooses-tooth-story')
    })

    it('leaves out "Read the story" without a story', () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight({ story: null }) } })
        expect(wrapper.find('[data-testid="spotlight-story"]').exists()).toBe(false)
    })

    it('credits the photo as its license asks', () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight() } })
        expect(wrapper.get('[data-testid="spotlight-credit"]').text()).toBe('Photo: LittleT889, CC BY-SA 4.0, via Wikimedia Commons')
    })

    it('asks Wikimedia for a size that fits the screen', () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight() } })
        expect(wrapper.get('[data-testid="spotlight-photo"]').attributes('srcset')).toContain('960px-Moose.jpg 960w')
    })

    it('drops the photo and its credit when the image fails to load', async () => {
        const wrapper = mount(FeaturedRestaurant, { props: { spotlight: spotlight() } })
        await wrapper.get('[data-testid="spotlight-photo"]').trigger('error')
        expect(wrapper.find('[data-testid="spotlight-photo"]').exists()).toBe(false)
        expect(wrapper.find('[data-testid="spotlight-credit"]').exists()).toBe(false)
    })
})
