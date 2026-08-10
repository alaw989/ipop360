import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import Dashboard from '@/Pages/Dashboard.vue'

vi.mock('@inertiajs/vue3', async () => {
    const actual = await vi.importActual('@inertiajs/vue3')
    return {
        ...actual as any,
        Head: { template: '<div />' },
    }
})

const stubs = {
    AuthenticatedLayout: { template: '<div><slot name="header" /><slot /></div>' },
}

function mountDashboard() {
    return mount(Dashboard, {
        global: { stubs },
    })
}

describe('Dashboard page', () => {
    it('renders the page heading', () => {
        const wrapper = mountDashboard()
        expect(wrapper.text()).toContain('Dashboard')
    })

    it('shows logged-in message', () => {
        const wrapper = mountDashboard()
        expect(wrapper.text()).toContain("You're logged in!")
    })

    it('renders the header in the slot', () => {
        const wrapper = mountDashboard()
        const heading = wrapper.find('h2')
        expect(heading.exists()).toBe(true)
        expect(heading.text()).toBe('Dashboard')
    })
})
