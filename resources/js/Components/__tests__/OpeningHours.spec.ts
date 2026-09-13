import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import OpeningHours from '@/Components/OpeningHours.vue'

const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']

function week(hours = '11 AM – 10 PM') {
    return { structured: true as const, week: days.map((day) => ({ day, hours })) }
}

describe('OpeningHours', () => {
    it('renders nothing when hours is null', () => {
        const wrapper = mount(OpeningHours, { props: { hours: null } })
        expect(wrapper.find('div').exists()).toBe(false)
    })

    it('shows the week in the order the server sends it, one row a day', () => {
        const hours = {
            structured: true as const,
            week: [
                { day: 'Monday', hours: 'Closed' },
                { day: 'Tuesday', hours: '11:30 AM – 2:30 PM, 5 PM – 10:30 PM' },
            ],
        }
        const wrapper = mount(OpeningHours, { props: { hours } })
        const rows = wrapper.findAll('tr')
        expect(rows).toHaveLength(2)
        expect(rows[0]!.text()).toContain('Monday')
        expect(rows[0]!.text()).toContain('Closed')
        expect(rows[1]!.text()).toContain('11:30 AM – 2:30 PM, 5 PM – 10:30 PM')
    })

    it('shows text that could not be read as a week', () => {
        const hours = { structured: false as const, raw_text: 'Mon-Fri 9am-5pm\nSat 10am-4pm' }
        const wrapper = mount(OpeningHours, { props: { hours } })
        expect(wrapper.find('table').exists()).toBe(false)
        expect(wrapper.text()).toContain('Mon-Fri 9am-5pm')
        expect(wrapper.text()).toContain('Sat 10am-4pm')
    })

    it("marks today's row once the page has loaded", async () => {
        const today = new Date().toLocaleDateString('en-US', { weekday: 'long' })
        const wrapper = mount(OpeningHours, { props: { hours: week() } })
        await wrapper.vm.$nextTick()
        const marked = wrapper.findAll('tr[data-today="true"]')
        expect(marked).toHaveLength(1)
        expect(marked[0]!.text()).toContain(today)
        expect(marked[0]!.text()).toContain('Today')
    })

    it('makes no "open now" claim (the hours carry no time zone)', () => {
        const wrapper = mount(OpeningHours, { props: { hours: week() } })
        expect(wrapper.text()).not.toMatch(/open now|closed now/i)
    })
})
