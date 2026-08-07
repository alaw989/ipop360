import { describe, it, expect, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
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

function getScripts() {
    return document.head.querySelectorAll('script[type="application/ld+json"]')
}

describe('JsonLd', () => {
    beforeEach(() => {
        document.head.querySelectorAll('script[type="application/ld+json"]').forEach((s) => s.remove())
    })

    it('injects a script element into document.head when data is provided', () => {
        mount(JsonLd, { props: { data: sampleData } })
        const scripts = getScripts()
        expect(scripts).toHaveLength(1)
    })

    it('script element has correct type application/ld+json', () => {
        mount(JsonLd, { props: { data: sampleData } })
        const script = getScripts()[0]
        expect(script.getAttribute('type')).toBe('application/ld+json')
    })

    it('script content equals JSON-stringified data', () => {
        mount(JsonLd, { props: { data: sampleData } })
        const script = getScripts()[0]
        expect(script.textContent).toBe(JSON.stringify(sampleData))
    })

    it('does not inject a script when data is null', () => {
        mount(JsonLd, { props: { data: null } })
        expect(getScripts()).toHaveLength(0)
    })

    it('removes script when data becomes null', async () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScripts()).toHaveLength(1)

        await wrapper.setProps({ data: null })
        expect(getScripts()).toHaveLength(0)
    })

    it('replaces script when data changes to different value', async () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScripts()[0].textContent).toBe(JSON.stringify(sampleData))

        await wrapper.setProps({ data: altData })
        const scripts = getScripts()
        expect(scripts).toHaveLength(1)
        expect(scripts[0].textContent).toBe(JSON.stringify(altData))
    })

    it('removes script on unmount', () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        expect(getScripts()).toHaveLength(1)

        wrapper.unmount()
        expect(getScripts()).toHaveLength(0)
    })

    it('replaces existing script when data changes identity', async () => {
        const wrapper = mount(JsonLd, { props: { data: sampleData } })
        const firstScript = getScripts()[0]

        await wrapper.setProps({ data: { ...sampleData } })
        const secondScript = getScripts()[0]

        expect(secondScript).not.toBe(firstScript)
        expect(getScripts()).toHaveLength(1)
    })
})
