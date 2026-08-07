import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ref, defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';
import { useCardGallery } from '@/composables/useCardGallery';

let photosRef = ref<string[]>([]);

function mountComposable(photos: string[] = []) {
    photosRef = ref(photos);
    let result: ReturnType<typeof useCardGallery> | null = null;
    const wrapper = mount(
        defineComponent({
            setup() {
                result = useCardGallery(() => photosRef.value);
                return () => h('div');
            },
        }),
    );
    return { wrapper, result: result! };
}

describe('useCardGallery', () => {
    beforeEach(() => {
        vi.stubGlobal('requestAnimationFrame', undefined);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    describe('isMulti', () => {
        it('is false when photos array is empty', () => {
            const { result } = mountComposable([]);
            expect(result.isMulti.value).toBe(false);
        });

        it('is false when only one photo', () => {
            const { result } = mountComposable(['/a.jpg']);
            expect(result.isMulti.value).toBe(false);
        });

        it('is true when two or more photos', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg']);
            expect(result.isMulti.value).toBe(true);
        });

        it('reacts when photos change', () => {
            const { result } = mountComposable(['/a.jpg']);
            expect(result.isMulti.value).toBe(false);
            photosRef.value = ['/a.jpg', '/b.jpg', '/c.jpg'];
            expect(result.isMulti.value).toBe(true);
        });
    });

    describe('activeIndex', () => {
        it('starts at 0', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            expect(result.activeIndex.value).toBe(0);
        });
    });

    describe('onLeave', () => {
        it('resets activeIndex to 0', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.activeIndex.value = 2;
            result.onLeave();
            expect(result.activeIndex.value).toBe(0);
        });
    });

    describe('goTo', () => {
        it('sets activeIndex to the given index', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.goTo(1);
            expect(result.activeIndex.value).toBe(1);
        });

        it('wraps around with modulo', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.goTo(3);
            expect(result.activeIndex.value).toBe(0);
        });

        it('handles negative index wrapping', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.goTo(-1);
            expect(result.activeIndex.value).toBe(2);
        });

        it('is a no-op when not isMulti', () => {
            const { result } = mountComposable(['/a.jpg']);
            result.goTo(0);
            expect(result.activeIndex.value).toBe(0);
        });
    });

    describe('prev', () => {
        it('moves to the previous photo', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.activeIndex.value = 1;
            result.prev();
            expect(result.activeIndex.value).toBe(0);
        });

        it('wraps from first to last', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.activeIndex.value = 0;
            result.prev();
            expect(result.activeIndex.value).toBe(2);
        });
    });

    describe('next', () => {
        it('moves to the next photo', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.activeIndex.value = 1;
            result.next();
            expect(result.activeIndex.value).toBe(2);
        });

        it('wraps from last to first', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            result.activeIndex.value = 2;
            result.next();
            expect(result.activeIndex.value).toBe(0);
        });
    });

    describe('onMove', () => {
        function makeEvent(el: Element | null, clientX: number) {
            return { currentTarget: el, clientX };
        }

        it('updates activeIndex based on cursor X position', () => {
            const { result, wrapper } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            const el = wrapper.element;
            vi.spyOn(el, 'getBoundingClientRect').mockReturnValue({ left: 0, width: 300 } as DOMRect);

            const event = makeEvent(el, 0);
            result.onMove(event as unknown as MouseEvent);
            expect(result.activeIndex.value).toBe(0);

            const event2 = makeEvent(el, 120);
            result.onMove(event2 as unknown as MouseEvent);
            expect(result.activeIndex.value).toBe(1);
        });

        it('clamps to last index when cursor is far right', () => {
            const { result, wrapper } = mountComposable(['/a.jpg', '/b.jpg', '/c.jpg']);
            const el = wrapper.element;
            vi.spyOn(el, 'getBoundingClientRect').mockReturnValue({ left: 0, width: 300 } as DOMRect);

            const event = makeEvent(el, 500);
            result.onMove(event as unknown as MouseEvent);
            expect(result.activeIndex.value).toBe(2);
        });

        it('is a no-op when not isMulti', () => {
            const { result, wrapper } = mountComposable(['/a.jpg']);
            const el = wrapper.element;
            vi.spyOn(el, 'getBoundingClientRect').mockReturnValue({ left: 0, width: 300 } as DOMRect);

            const event = makeEvent(el, 150);
            result.onMove(event as unknown as MouseEvent);
            expect(result.activeIndex.value).toBe(0);
        });

        it('is a no-op when currentTarget is null', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg']);
            const event = makeEvent(null, 150);
            expect(() => result.onMove(event as unknown as MouseEvent)).not.toThrow();
            expect(result.activeIndex.value).toBe(0);
        });
    });

    describe('onEnter', () => {
        it('does not throw', () => {
            const { result } = mountComposable(['/a.jpg', '/b.jpg']);
            expect(() => result.onEnter()).not.toThrow();
        });
    });

    describe('lifecycle', () => {
        it('registers scroll and resize listeners on mount', () => {
            const addSpy = vi.spyOn(window, 'addEventListener');
            const { wrapper } = mountComposable(['/a.jpg']);
            expect(addSpy).toHaveBeenCalledWith('scroll', expect.any(Function), { passive: true });
            expect(addSpy).toHaveBeenCalledWith('resize', expect.any(Function));
            wrapper.unmount();
        });

        it('removes scroll and resize listeners on unmount', () => {
            const rmSpy = vi.spyOn(window, 'removeEventListener');
            const { wrapper } = mountComposable(['/a.jpg']);
            wrapper.unmount();
            expect(rmSpy).toHaveBeenCalledWith('scroll', expect.any(Function));
            expect(rmSpy).toHaveBeenCalledWith('resize', expect.any(Function));
        });
    });
});
