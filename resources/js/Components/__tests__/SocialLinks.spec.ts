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

    it('renders a button for each platform', () => {
        const links = [
            { platform: 'facebook', url: 'https://facebook.com/test', followers: null },
            { platform: 'instagram', url: 'https://instagram.com/test', followers: 1000 },
        ]
        const wrapper = mount(SocialLinks, {
            props: { links },
            global: { stubs },
        })
        const buttons = wrapper.findAll('button')
        expect(buttons.length).toBe(2)
        expect(buttons[0].text()).toContain('Facebook')
        expect(buttons[1].text()).toContain('Instagram')
    })

    it('shows follower count in title attribute', () => {
        const links = [
            { platform: 'youtube', url: 'https://youtube.com/@test', followers: 50000 },
        ]
        const wrapper = mount(SocialLinks, {
            props: { links },
            global: { stubs },
        })
        const button = wrapper.find('button')
        expect(button.attributes('title')).toContain('50,000')
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
