import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ScoreBreakdown from '@/Components/ScoreBreakdown.vue'

function makeBreakdown(overrides = {}) {
    return {
        signals: [
            { label: 'Quality', weight: 0.35, normalized: 0.35, contribution: 0.2 },
            { label: 'Social Links', weight: 0.2, normalized: 0.2, contribution: 0.1, detail: '3 links' },
        ],
        total: 0.3,
        ...overrides,
    }
}

function barSegments(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('.flex.h-2.w-full > div')
}

describe('ScoreBreakdown', () => {
    it('renders the rounded score as a percentage', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(wrapper.text()).toContain('Score 30%')
    })

    it('renders a single full-width "No data" segment when total is zero', () => {
        const wrapper = mount(ScoreBreakdown, {
            props: { breakdown: makeBreakdown({ total: 0 }) },
        })
        expect(barSegments(wrapper)).toHaveLength(1)
        expect(barSegments(wrapper)[0].attributes('style')).toContain('100%')
        expect(wrapper.text()).not.toContain('Quality')
    })

    it('renders a single "No data" segment when there are no signals', () => {
        const wrapper = mount(ScoreBreakdown, {
            props: { breakdown: { signals: [], total: 0.5 } },
        })
        expect(barSegments(wrapper)).toHaveLength(1)
        expect(wrapper.text()).not.toContain('Quality')
    })

    it('renders a single "No data" segment when all contributions are zero', () => {
        const wrapper = mount(ScoreBreakdown, {
            props: {
                breakdown: {
                    signals: [{ label: 'Quality', weight: 0.35, normalized: 1, contribution: 0 }],
                    total: 0.5,
                },
            },
        })
        expect(barSegments(wrapper)).toHaveLength(1)
        expect(wrapper.text()).not.toContain('Quality')
    })

    it('renders one bar segment per active signal', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(barSegments(wrapper)).toHaveLength(2)
    })

    it('lists each signal label with its contribution percentage', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(wrapper.text()).toContain('Quality')
        expect(wrapper.text()).toContain('20%')
        expect(wrapper.text()).toContain('Social Links')
        expect(wrapper.text()).toContain('10%')
    })

    it('renders the detail text for signals that provide it', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(wrapper.text()).toContain('3 links')
    })

    it('does not render detail text when a signal has none', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(wrapper.text()).not.toContain('undefined')
    })

    it('gives a bar segment a minimum width of 5%', () => {
        const wrapper = mount(ScoreBreakdown, {
            props: {
                breakdown: {
                    signals: [{ label: 'Quality', weight: 1, normalized: 1, contribution: 0.001 }],
                    total: 0.5,
                },
            },
        })
        expect(barSegments(wrapper)[0].attributes('style')).toContain('5%')
    })

    it('sizes bar segments proportionally to contribution', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(barSegments(wrapper)[0].attributes('style')).toContain('66.66666666666667%')
        expect(barSegments(wrapper)[1].attributes('style')).toContain('33.333333333333336%')
    })
})