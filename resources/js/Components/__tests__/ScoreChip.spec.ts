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
    })

    it('renders "Popular" for score 0.6–0.79', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.68 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Popular')
    })

    it('renders "Top rated" for score 0.8–0.89', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.85 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Top rated')
    })

    it('renders "Elite" for score >= 0.9', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: 0.94 },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Elite')
    })

    it('handles string total input', () => {
        const wrapper = mount(ScoreChip, {
            props: { total: '0.82' },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Top rated')
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

    it('never shows the internal score as a percentage on the chip', () => {
        const wrapper = mount(ScoreChip, { props: { total: 0.94 }, global: { stubs } })
        expect(wrapper.get('[data-testid="score-chip"]').text()).not.toMatch(/%/)
    })

    it('is a solid, readable chip, not a see-through tint', () => {
        const wrapper = mount(ScoreChip, { props: { total: 0.7 }, global: { stubs } })
        const chip = wrapper.get('[data-testid="score-chip"]')
        expect(chip.classes()).toEqual(expect.arrayContaining(['bg-muted', 'text-foreground', 'text-xs']))
        expect(chip.classes().some((c) => c.includes('backdrop-blur'))).toBe(false)
    })

    it('offers "Why it ranks here" as a link on result cards', () => {
        const breakdown = { total: 0.5, signals: [{ label: 'Quality', weight: 0.4, normalized: 0.9, contribution: 0.36 }] }
        const wrapper = mount(ScoreChip, { props: { total: 0.2, variant: 'link', breakdown }, global: { stubs } })
        expect(wrapper.get('[data-testid="score-why"]').text()).toContain('Why it ranks here')
    })
})
