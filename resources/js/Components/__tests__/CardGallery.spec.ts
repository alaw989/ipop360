import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'

vi.mock('@lucide/vue', () => ({
    ChevronLeft: { template: '<button class="chevron-left" data-testid="chevron-left"><slot /></button>' },
    ChevronRight: { template: '<button class="chevron-right" data-testid="chevron-right"><slot /></button>' },
}))

const activeIdx = ref(0)
const isMultiVal = ref(false)
const prevFn = vi.fn()
const nextFn = vi.fn()
const onMoveFn = vi.fn()
const onEnterFn = vi.fn()
const onLeaveFn = vi.fn()

vi.mock('@/composables/useCardGallery', () => ({
    useCardGallery: () => ({
        activeIndex: activeIdx,
        isMulti: isMultiVal,
        prev: prevFn,
        next: nextFn,
        onMove: onMoveFn,
        onEnter: onEnterFn,
        onLeave: onLeaveFn,
    }),
}))

import CardGallery from '@/Components/CardGallery.vue'

function mountGallery(props: Partial<{
    photos: string[]
    gradient: string
    alt: string
    aspect: '4/3' | '3/2'
    multi: boolean
    roundedClass: string
    eager: boolean
}> = {}) {
    return mount(CardGallery, {
        props: {
            photos: props.photos ?? [],
            gradient: props.gradient ?? 'linear-gradient(to bottom, #fff, #ccc)',
            alt: props.alt ?? 'Test photo',
            aspect: props.aspect ?? '4/3',
            multi: props.multi ?? true,
            roundedClass: props.roundedClass ?? 'rounded-t-2xl',
            eager: props.eager ?? false,
        },
    })
}

describe('CardGallery', () => {
    let matchMediaListener: ((e: MediaQueryListEvent) => void) | null = null

    beforeEach(() => {
        activeIdx.value = 0
        isMultiVal.value = false
        prevFn.mockClear()
        nextFn.mockClear()
        onMoveFn.mockClear()
        onEnterFn.mockClear()
        onLeaveFn.mockClear()
        vi.useFakeTimers()

        matchMediaListener = null
        const mql = {
            matches: true,
            media: '(hover: hover)',
            addEventListener: vi.fn((_event: string, listener: (e: MediaQueryListEvent) => void) => {
                matchMediaListener = listener
            }),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
            onchange: null,
        }
        window.matchMedia = vi.fn(() => mql) as unknown as Window['matchMedia']

        window.IntersectionObserver = vi.fn(function (this: any) {
            this.observe = vi.fn()
            this.unobserve = vi.fn()
            this.disconnect = vi.fn()
        }) as unknown as typeof IntersectionObserver
    })

    afterEach(() => {
        vi.useRealTimers()
    })

    describe('rendering', () => {
        it('renders gradient backdrop', () => {
            const wrapper = mountGallery({ photos: [] })
            const gradientDiv = wrapper.find('.absolute.inset-0')
            expect(gradientDiv.attributes('style')).toContain('linear-gradient')
        })

        it('renders dark scrim when no hero photo', () => {
            const wrapper = mountGallery({ photos: [] })
            expect(wrapper.find('.bg-black\\/10').exists()).toBe(true)
        })

        it('does not render dark scrim when hero photo exists', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg'] })
            expect(wrapper.find('.bg-black\\/10').exists()).toBe(false)
        })

        it('renders hero image with alt text', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], alt: 'Beautiful photo' })
            const heroImg = wrapper.find('img[alt="Beautiful photo"]')
            expect(heroImg.exists()).toBe(true)
            expect(heroImg.attributes('src')).toBe('/img/a.jpg')
        })

        it('does not render hero image when no photos', () => {
            const wrapper = mountGallery({ photos: [] })
            expect(wrapper.find('img').exists()).toBe(false)
        })

        it('renders bottom readability scrim', () => {
            const wrapper = mountGallery({ photos: [] })
            const scrims = wrapper.findAll('.from-black\\/55')
            expect(scrims.length).toBeGreaterThan(0)
        })

        it('renders slot overlays', () => {
            const wrapper = mount(CardGallery, {
                props: {
                    photos: [],
                    gradient: 'linear-gradient(to bottom, #fff, #ccc)',
                    alt: 'Test',
                },
                slots: { overlays: '<div class="overlay-stub">Overlay Content</div>' },
            })
            expect(wrapper.find('.overlay-stub').exists()).toBe(true)
            expect(wrapper.find('.overlay-stub').text()).toBe('Overlay Content')
        })

        it('applies roundedClass prop', () => {
            const wrapper = mountGallery({ photos: [], roundedClass: 'rounded-xl' })
            expect(wrapper.find('.rounded-xl').exists()).toBe(true)
        })

        it('applies aspect-4/3 by default', () => {
            const wrapper = mountGallery({ photos: [] })
            expect(wrapper.find('.aspect-\\[4\\/3\\]').exists()).toBe(true)
        })

        it('applies aspect-3/2 when aspect prop is 3/2', () => {
            const wrapper = mountGallery({ photos: [], aspect: '3/2' })
            expect(wrapper.find('.aspect-\\[3\\/2\\]').exists()).toBe(true)
        })
    })

    describe('image attributes', () => {
        it('sets eager loading when eager is true', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], eager: true })
            const img = wrapper.find('img')
            expect(img.attributes('loading')).toBe('eager')
            expect(img.attributes('fetchpriority')).toBe('high')
        })

        it('sets lazy loading when eager is false', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], eager: false })
            const img = wrapper.find('img')
            expect(img.attributes('loading')).toBe('lazy')
            expect(img.attributes('fetchpriority')).toBe('auto')
        })

        it('sets width and height for CLS prevention at 4/3', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], aspect: '4/3' })
            const img = wrapper.find('img')
            expect(img.attributes('width')).toBe('400')
            expect(img.attributes('height')).toBe('300')
        })

        it('sets width and height for CLS prevention at 3/2', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], aspect: '3/2' })
            const img = wrapper.find('img')
            expect(img.attributes('width')).toBe('400')
            expect(img.attributes('height')).toBe('267')
        })

        it('sets sizes attribute for responsive images', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg'] })
            const img = wrapper.find('img')
            expect(img.attributes('sizes')).toBe('(max-width: 640px) 100vw, (max-width: 1024px) 50vw, 33vw')
        })

        it('applies opacity-100 to hero when activeIndex is 0', () => {
            activeIdx.value = 0
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'] })
            const img = wrapper.find('img[alt="Test photo"]')
            expect(img.classes()).toContain('opacity-100')
        })

        it('applies opacity-0 to hero when activeIndex is not 0', () => {
            activeIdx.value = 1
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'] })
            const img = wrapper.find('img[alt="Test photo"]')
            expect(img.classes()).toContain('opacity-0')
        })
    })

    describe('gallery controls', () => {
        it('shows gallery controls when multi with 2+ photos', () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: true })
            expect(wrapper.find('[data-testid="chevron-left"]').exists()).toBe(true)
            expect(wrapper.find('[data-testid="chevron-right"]').exists()).toBe(true)
        })

        it('hides gallery controls when multi=false', () => {
            isMultiVal.value = false
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: false })
            expect(wrapper.find('[data-testid="chevron-left"]').exists()).toBe(false)
        })

        it('hides gallery controls when single photo', () => {
            isMultiVal.value = false
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], multi: true })
            expect(wrapper.find('[data-testid="chevron-left"]').exists()).toBe(false)
        })

        it('left chevron click triggers prev', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            const leftChevron = wrapper.find('[data-testid="chevron-left"]')
            const leftBtn = leftChevron.element.closest('button') as HTMLElement
            if (leftBtn) {
                leftBtn.click()
            }
            expect(prevFn).toHaveBeenCalled()
        })

        it('right chevron click triggers next', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            const rightChevron = wrapper.find('[data-testid="chevron-right"]')
            const rightBtn = rightChevron.element.closest('button') as HTMLElement
            if (rightBtn) {
                rightBtn.click()
            }
            expect(nextFn).toHaveBeenCalled()
        })

        it('left tap zone triggers prev on click', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: true })
            const buttons = wrapper.findAll('button')
            const leftTap = buttons[0]
            await leftTap.trigger('click')
            expect(prevFn).toHaveBeenCalled()
        })

        it('right tap zone triggers next on click', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: true })
            const buttons = wrapper.findAll('button')
            const rightTap = buttons[1]
            await rightTap.trigger('click')
            expect(nextFn).toHaveBeenCalled()
        })

        it('renders dots for each photo', () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            const dots = wrapper.findAll('.h-1\\.5.rounded-full')
            expect(dots).toHaveLength(3)
        })

        it('active dot has bg-white class', () => {
            activeIdx.value = 1
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            const dots = wrapper.findAll('.h-1\\.5.rounded-full')
            expect(dots[0].classes()).toContain('bg-white/50')
            expect(dots[1].classes()).toContain('bg-white')
            expect(dots[2].classes()).toContain('bg-white/50')
        })

        it('active dot has wider width w-3', () => {
            activeIdx.value = 2
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            const dots = wrapper.findAll('.h-1\\.5.rounded-full')
            expect(dots[2].classes()).toContain('w-3')
            expect(dots[0].classes()).toContain('w-1.5')
        })

        it('counter shows "1/3" format', () => {
            activeIdx.value = 0
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            const counter = wrapper.find('.tabular-nums')
            expect(counter.exists()).toBe(true)
            expect(counter.text()).toBe('1/3')
        })

        it('counter updates to "2/3" when activeIndex is 1', () => {
            activeIdx.value = 1
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            const counter = wrapper.find('.tabular-nums')
            expect(counter.text()).toBe('2/3')
        })
    })

    describe('mouse events', () => {
        it('calls handleEnter on mouseenter', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: true })
            await wrapper.find('.relative').trigger('mouseenter')
            expect(onEnterFn).toHaveBeenCalled()
        })

        it('does not call onEnter when not galleryActive', async () => {
            isMultiVal.value = false
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], multi: true })
            await wrapper.find('.relative').trigger('mouseenter')
            expect(onEnterFn).not.toHaveBeenCalled()
        })

        it('calls onLeave on mouseleave when galleryActive', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: true })
            await wrapper.find('.relative').trigger('mouseleave')
            expect(onLeaveFn).toHaveBeenCalled()
        })

        it('does not call onLeave when not galleryActive', async () => {
            isMultiVal.value = false
            const wrapper = mountGallery({ photos: ['/img/a.jpg'], multi: true })
            await wrapper.find('.relative').trigger('mouseleave')
            expect(onLeaveFn).not.toHaveBeenCalled()
        })

        it('calls onMove on mousemove when galleryActive and expanded', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: true })
            await wrapper.find('.relative').trigger('mouseenter')
            await wrapper.find('.relative').trigger('mousemove')
            expect(onMoveFn).toHaveBeenCalled()
        })

        it('does not call onMove before expanded', () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: true })
            wrapper.find('.relative').trigger('mousemove')
            expect(onMoveFn).not.toHaveBeenCalled()
        })
    })

    describe('non-hero image expansion', () => {
        it('does not render non-hero images when not expanded', () => {
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg'], multi: false })
            const imgs = wrapper.findAll('img')
            expect(imgs).toHaveLength(1)
        })

        it('renders non-hero images after expand (via mouseenter)', async () => {
            isMultiVal.value = true
            const wrapper = mountGallery({ photos: ['/img/a.jpg', '/img/b.jpg', '/img/c.jpg'], multi: true })
            expect(wrapper.findAll('img')).toHaveLength(1)
            await wrapper.find('.relative').trigger('mouseenter')
            expect(wrapper.findAll('img')).toHaveLength(3)
        })
    })

    describe('lifecycle', () => {
        it('registers matchMedia listener on mount', () => {
            mountGallery({ photos: [] })
            const mql = window.matchMedia('(hover: hover)')
            expect(mql.addEventListener).toHaveBeenCalledWith('change', expect.any(Function))
        })

        it('removes matchMedia listener on unmount', () => {
            const wrapper = mountGallery({ photos: [] })
            wrapper.unmount()
            const mql = window.matchMedia('(hover: hover)')
            expect(mql.removeEventListener).toHaveBeenCalledWith('change', expect.any(Function))
        })
    })
})
