import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import AppFooter from '@/Components/AppFooter.vue'

function createWrapper() {
    return mount(AppFooter, {
        global: {
            config: {
                globalProperties: {
                    $page: { props: { auth: { user: null } } },
                },
            },
            stubs: {
                Link: { template: '<a><slot /></a>' },
            },
        },
    })
}

describe('AppFooter', () => {
    it('renders the company name', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('iPop360')
    })

    it('renders navigation links', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('Home')
        expect(wrapper.text()).toContain('Browse')
    })

    it('renders the copyright notice with current year', () => {
        const wrapper = createWrapper()
        const year = new Date().getFullYear()
        expect(wrapper.text()).toContain(String(year))
    })

    it('renders the Similarweb attribution link', () => {
        const wrapper = createWrapper()
        expect(wrapper.text()).toContain('Competitive analysis')
    })
})
