import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ScoreBreakdown from '@/Components/ScoreBreakdown.vue'

function makeBreakdown(overrides = {}) {
    return {
        signals: [
            { label: 'Profile Completeness', weight: 0.2, normalized: 0.5, contribution: 0.1, detail: 'How complete its listing is.' },
            { label: 'Quality', weight: 0.35, normalized: 0.9, contribution: 0.3, detail: '4.8 stars from 210 reviews.' },
            { label: 'Page Views', weight: 0.05, normalized: 0, contribution: 0 },
        ],
        total: 0.4,
        ...overrides,
    }
}

function items(wrapper: ReturnType<typeof mount>) {
    return wrapper.findAll('[data-testid="score-signals"] li')
}

describe('ScoreBreakdown', () => {
    it('lists the signals that counted, biggest first, in words a diner reads', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        const names = items(wrapper).map((li) => li.find('p').text())
        expect(names).toEqual(['Ratings', 'Complete listing'])
    })

    it("keeps the scorer's own sentence for each", () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(items(wrapper)[0]!.text()).toContain('4.8 stars from 210 reviews.')
    })

    it('shows no overall percentage', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        expect(wrapper.text()).not.toMatch(/\d+%/)
    })

    it('sizes each bar against the biggest signal', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown() } })
        const widths = wrapper.findAll('[data-testid="score-signals"] li [aria-hidden="true"] > div').map((d) => d.attributes('style'))
        expect(widths[0]).toContain('width: 100%')
        expect(widths[1]).toContain('width: 33.3')
    })

    it('falls back to the label when there is no plain name for it', () => {
        const wrapper = mount(ScoreBreakdown, {
            props: { breakdown: makeBreakdown({ signals: [{ label: 'Something New', weight: 1, normalized: 1, contribution: 0.5 }] }) },
        })
        expect(items(wrapper)[0]!.find('p').text()).toBe('Something New')
    })

    it('says so when nothing is known yet', () => {
        const wrapper = mount(ScoreBreakdown, { props: { breakdown: makeBreakdown({ signals: [], total: 0 }) } })
        expect(wrapper.text()).toContain('Not enough is known')
    })
})
