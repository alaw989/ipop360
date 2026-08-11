import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import RestaurantCardSkeleton from '@/Components/RestaurantCardSkeleton.vue'

describe('RestaurantCardSkeleton', () => {
    it('renders the outer card container with expected classes', () => {
        const wrapper = mount(RestaurantCardSkeleton)
        const card = wrapper.find('div')
        expect(card.classes()).toContain('overflow-hidden')
        expect(card.classes()).toContain('rounded-2xl')
        expect(card.classes()).toContain('border')
        expect(card.classes()).toContain('bg-card')
    })

    it('renders six skeleton placeholders', () => {
        const wrapper = mount(RestaurantCardSkeleton)
        const skeletons = wrapper.findAll('[data-slot="skeleton"]')
        expect(skeletons).toHaveLength(6)
    })

    it('renders a full-width image skeleton with 4:3 aspect ratio', () => {
        const wrapper = mount(RestaurantCardSkeleton)
        const imageSkeleton = wrapper.find('[data-slot="skeleton"]')
        expect(imageSkeleton.classes()).toContain('aspect-[4/3]')
        expect(imageSkeleton.classes()).toContain('w-full')
        expect(imageSkeleton.classes()).toContain('rounded-none')
    })

    it('renders title skeleton with correct sizing', () => {
        const wrapper = mount(RestaurantCardSkeleton)
        const skeletons = wrapper.findAll('[data-slot="skeleton"]')
        const titleSkeleton = skeletons[1]
        expect(titleSkeleton.classes()).toContain('h-4')
        expect(titleSkeleton.classes()).toContain('w-3/4')
    })

    it('renders subtitle skeleton with correct sizing', () => {
        const wrapper = mount(RestaurantCardSkeleton)
        const skeletons = wrapper.findAll('[data-slot="skeleton"]')
        const subtitleSkeleton = skeletons[2]
        expect(subtitleSkeleton.classes()).toContain('h-3')
        expect(subtitleSkeleton.classes()).toContain('w-1/2')
    })

    it('renders description skeleton with correct sizing', () => {
        const wrapper = mount(RestaurantCardSkeleton)
        const skeletons = wrapper.findAll('[data-slot="skeleton"]')
        const descSkeleton = skeletons[3]
        expect(descSkeleton.classes()).toContain('h-3')
        expect(descSkeleton.classes()).toContain('w-2/3')
    })

    it('renders two cuisine chip skeletons with pill shape and correct size', () => {
        const wrapper = mount(RestaurantCardSkeleton)
        const skeletons = wrapper.findAll('[data-slot="skeleton"]')
        const chip1 = skeletons[4]
        const chip2 = skeletons[5]

        expect(chip1.classes()).toContain('h-5')
        expect(chip1.classes()).toContain('w-12')
        expect(chip1.classes()).toContain('rounded-full')

        expect(chip2.classes()).toContain('h-5')
        expect(chip2.classes()).toContain('w-12')
        expect(chip2.classes()).toContain('rounded-full')
    })
})
