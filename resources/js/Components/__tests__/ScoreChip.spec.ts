import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ScoreChip from '@/Components/ScoreChip.vue'

const stubs = { Star: true, BadgeCheck: true, Flame: true, TrendingUp: true }

describe('ScoreChip', () => {
    it('renders nothing for score < 0.4', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.2 },
            global: { stubs },
        })
        expect(wrapper.find('button').exists()).toBe(false)
    })

    it('renders "Rising" for score 0.4–0.59', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.45 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Rising')
        expect(wrapper.text()).toContain('45%')
    })

    it('renders "Popular" for score 0.6–0.79', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.68 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Popular')
        expect(wrapper.text()).toContain('68%')
    })

    it('renders "Top Rated" for score 0.8–0.89', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.85 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Top Rated')
        expect(wrapper.text()).toContain('85%')
    })

    it('renders "Elite" for score >= 0.9', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.94 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Elite')
        expect(wrapper.text()).toContain('94%')
    })

    it('handles string total input', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: '0.82' },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Top Rated')
        expect(wrapper.text()).toContain('82%')
    })

    it('renders boundary score of exactly 0.9 as Elite', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.9 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Elite')
    })

    it('renders boundary score of exactly 0.4 as Rising', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.4 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Rising')
    })
})
