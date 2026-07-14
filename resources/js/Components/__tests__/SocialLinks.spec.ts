import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SocialLinks from '@/Components/SocialLinks.vue'

const stubs = { Globe: true }

describe('SocialLinks', () => {
    it('renders nothing when links array is empty', () => {
        const wrapper = mount(SocialLinks, {
            props: { links: [] },
            global: { stubs },
        })
        expect(wrapper.find('div').exists()).toBe(false)
    })

    it('renders a link for each platform', () => {
        const links = [
            { platform: 'facebook', url: 'https://facebook.com/test', followers: null },
            { platform: 'instagram', url: 'https://instagram.com/test', followers: 1000 },
        ]
        const wrapper = mount(SocialLinks, {
            props: { links },
            global: { stubs },
        })
        const anchors = wrapper.findAll('a')
        expect(anchors.length).toBe(2)
        expect(anchors[0].text()).toContain('Facebook')
        expect(anchors[1].text()).toContain('Instagram')
    })

    it('opens links in new tab with rel attributes', () => {
        const links = [
            { platform: 'twitter', url: 'https://twitter.com/test', followers: null },
        ]
        const wrapper = mount(SocialLinks, {
            props: { links },
            global: { stubs },
        })
        const anchor = wrapper.find('a')
        expect(anchor.attributes('target')).toBe('_blank')
        expect(anchor.attributes('rel')).toBe('noopener noreferrer')
    })

    it('shows follower count in title attribute', () => {
        const links = [
            { platform: 'youtube', url: 'https://youtube.com/@test', followers: 50000 },
        ]
        const wrapper = mount(SocialLinks, {
            props: { links },
            global: { stubs },
        })
        const anchor = wrapper.find('a')
        expect(anchor.attributes('title')).toContain('50,000')
    })

    it('uses platform label as fallback for unknown platforms', () => {
        const links = [
            { platform: 'unknown', url: 'https://example.com', followers: null },
        ]
        const wrapper = mount(SocialLinks, {
            props: { links },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('unknown')
    })
})
