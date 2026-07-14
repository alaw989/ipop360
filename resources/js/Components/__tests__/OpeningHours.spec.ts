import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import OpeningHours from '@/Components/OpeningHours.vue'

const stubs = { Clock: true }

describe('OpeningHours', () => {
    it('renders nothing when hours is null', () => {
        const wrapper = mount(OpeningHours, {
            props: { hours: null },
            global: { stubs },
        })
        expect(wrapper.find('div').exists()).toBe(false)
    })

    it('renders structured hours in a table', () => {
        const hours = {
            structured: true,
            hours: [
                { day: 'Monday', open: '09:00', close: '17:00' },
                { day: 'Tuesday', open: '09:00', close: '17:00' },
            ],
        }
        const wrapper = mount(OpeningHours, {
            props: { hours },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Hours')
        expect(wrapper.text()).toContain('Monday')
        expect(wrapper.text()).toContain('09:00')
        expect(wrapper.text()).toContain('17:00')
        expect(wrapper.text()).toContain('Tuesday')
    })

    it('sorts structured hours by day order', () => {
        const hours = {
            structured: true,
            hours: [
                { day: 'Sunday', open: '10:00', close: '18:00' },
                { day: 'Monday', open: '09:00', close: '17:00' },
            ],
        }
        const wrapper = mount(OpeningHours, {
            props: { hours },
            global: { stubs },
        })
        // Monday should appear before Sunday
        const rows = wrapper.findAll('tr')
        expect(rows[0].text()).toContain('Monday')
        expect(rows[1].text()).toContain('Sunday')
    })

    it('renders raw text hours', () => {
        const hours = {
            structured: false,
            raw_text: 'Mon-Fri 9am-5pm\nSat 10am-4pm',
        }
        const wrapper = mount(OpeningHours, {
            props: { hours },
            global: { stubs },
        })
        expect(wrapper.text()).toContain('Hours')
        expect(wrapper.text()).toContain('Mon-Fri 9am-5pm')
        expect(wrapper.text()).toContain('Sat 10am-4pm')
    })
})
