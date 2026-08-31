import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div><slot /></div>' },
}))

import JsonLd from '@/Components/JsonLd.vue'

const sampleData = {
    '@context': 'https://schema.org',
    '@type': 'Restaurant',
    name: 'Test Restaurant',
}

const altData = {
    '@context': 'https://schema.org',
    '@type': 'Organization',
    name: 'Another Org',
}

function getScript(wrapper: ReturnType<typeof mount>) {
    return wrapper.find('script[type="application/ld+json"]')
}

describe('JsonLd', () => {
    it('renders a script element into the Head when data is provided', () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScript(wrapper).exists()).toBe(true)
    })

    it('script element has correct type application/ld+json', () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScript(wrapper).attributes('type')).toBe('application/ld+json')
    })

    it('script content equals JSON-stringified data', () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScript(wrapper).text()).toBe(JSON.stringify(sampleData))
    })

    it('escapes < in the serialized JSON so a stray </script>-like substring cannot break out of the tag', () => {
        const dangerousData = { '@type': 'Restaurant', name: 'A </script><script>alert(1)</script> Diner' }
        const wrapper = mount(JsonLd, { props: { data: dangerousData } })
        expect(getScript(wrapper).text()).not.toContain('</script>')
        expect(getScript(wrapper).text()).toContain('\\u003c')
        expect(JSON.parse(getScript(wrapper).text())).toEqual(dangerousData)
    })

    it('does not render a script when data is null', () => {
        const wrapper = mount(JsonLd, { props: { data: null } })
        expect(getScript(wrapper).exists()).toBe(false)
    })

    it('removes the script when data becomes null', async () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScript(wrapper).exists()).toBe(true)

        await wrapper.setProps({ data: null })
        expect(getScript(wrapper).exists()).toBe(false)
    })

    it('updates the script content when data changes to a different value', async () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScript(wrapper).text()).toBe(JSON.stringify(sampleData))

        await wrapper.setProps({ data: altData })
        expect(getScript(wrapper).text()).toBe(JSON.stringify(altData))
    })
})
