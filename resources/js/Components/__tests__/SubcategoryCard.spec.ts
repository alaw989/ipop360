import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SubcategoryCard from '@/Components/SubcategoryCard.vue'

const cuisine = {
    id: 1,
    name: 'Italian',
    slug: 'italian',
    description: 'Pasta, pizza and more',
    icon: '🍝',
}

describe('SubcategoryCard', () => {
    it('renders the cuisine icon and name', () => {
        const wrapper = mount(SubcategoryCard, { props: { cuisine } })
        expect(wrapper.text()).toContain('🍝')
        expect(wrapper.find('h3').text()).toBe('Italian')
    })

    it('renders the description when present', () => {
        const wrapper = mount(SubcategoryCard, { props: { cuisine } })
        expect(wrapper.text()).toContain('Pasta, pizza and more')
    })

    it('omits the description when it is null', () => {
        const wrapper = mount(SubcategoryCard, {
            props: { cuisine: { ...cuisine, description: null } },
        })
        expect(wrapper.text()).not.toContain('Pasta, pizza and more')
    })

    it('emits select with the cuisine slug on click', async () => {
        const wrapper = mount(SubcategoryCard, { props: { cuisine } })
        await wrapper.find('[data-slot="card"]').trigger('click')
        expect(wrapper.emitted('select')).toHaveLength(1)
        expect(wrapper.emitted('select')![0]).toEqual(['italian'])
    })

    it('emits select with the cuisine slug when icon and description are absent', async () => {
        const wrapper = mount(SubcategoryCard, {
            props: { cuisine: { id: 2, name: 'Sushi', slug: 'sushi', description: null, icon: null } },
        })
        await wrapper.find('[data-slot="card"]').trigger('click')
        expect(wrapper.emitted('select')![0]).toEqual(['sushi'])
    })
})
