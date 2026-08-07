import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StickySearchBar from '@/Components/StickySearchBar.vue'

const linkStub = {
    component: { template: '<a :href="" @click.prevent=""><slot /></a>' },
}

function createWrapper(location = { city: 'Miami', state: 'FL' }, user = null) {
    return mount(StickySearchBar, {
        props: { location },
        global: {
            config: {
                globalProperties: {
                    $page: { props: { auth: { user } } },
                },
            },
            stubs: {
                Link: { template: '<a><slot /></a>' },
                Button: { template: '<button><slot /></button>' },
                Badge: { template: '<span><slot /></span>' },
                BrandLogo: { template: '<span>iPop360</span>' },
                Search: { template: '<span />' },
                MapPin: { template: '<span />' },
            },
        },
    })
}

describe('StickySearchBar', () => {
    it('renders the brand and beta badge', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('iPop360')
        expect(wrapper.text()).toContain('Beta')
    })

    it('displays the city from location', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('Miami')
    })

    it('falls back to state when city is null', () => {
        const wrapper = createWrapper({ city: null, state: 'FL' })
        expect(wrapper.text()).toContain('FL')
    })

    it('shows "Everywhere" when city and state are both null', () => {
        const wrapper = createWrapper({ city: null, state: null })
        expect(wrapper.text()).toContain('Everywhere')
    })

    it('emits refineSearch when the logo link is clicked', async () => {
        const wrapper = createWrapper()
        await wrapper.find('a[aria-label="iPop360 home"]').trigger('click')
        expect(wrapper.emitted('refineSearch')).toBeTruthy()
    })

    it('emits refineSearch when the search icon button is clicked', async () => {
        const wrapper = createWrapper()
        await wrapper.find('button[aria-label="Refine search"]').trigger('click')
        expect(wrapper.emitted('refineSearch')).toBeTruthy()
    })

    it('hides the Favorites link for guests', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).not.toContain('Favorites')
    })

    it('shows the Favorites link for authed users', () => {
        const wrapper = createWrapper({ city: 'Miami', state: 'FL' }, { id: 1 })
        expect(wrapper.text()).toContain('Favorites')
    })
})