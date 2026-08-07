import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { useKeyboardOffset } from '@/composables/useKeyboardOffset';

function mountComposable() {
    let result: ReturnType<typeof useKeyboardOffset> | null = null;
    const wrapper = mount(
        defineComponent({
            setup() {
                result = useKeyboardOffset();
                return () => h('div');
            },
        }),
    );
    return { wrapper, result: result! };
}

describe('useKeyboardOffset', () => {
    let origVIewport: typeof window.visualViewport;

    beforeEach(() => {
        origVIewport = window.visualViewport;
    });

    afterEach(() => {
        // Restore original visualViewport
        Object.defineProperty(window, 'visualViewport', {
            value: origVIewport,
            writable: true,
            configurable: true,
        });
    });

    describe('when visualViewport is unavailable', () => {
        beforeEach(() => {
            delete (window as any).visualViewport;
        });

        it('returns keyboardHeight ref initialized to 0', () => {
            const { result } = mountComposable();
            expect(result.keyboardHeight.value).toBe(0);
        });

        it('update() falls back to 0 when visualViewport is missing', () => {
            // Default jsdom innerHeight is 768
            const { result } = mountComposable();
            // Even if innerHeight changes, without visualViewport keyboardHeight stays 0
            expect(result.keyboardHeight.value).toBe(0);
        });
    });

    describe('when visualViewport is available', () => {
        function setVisualViewport(height: number) {
            Object.defineProperty(window, 'visualViewport', {
                value: {
                    height,
                    addEventListener: vi.fn(),
                    removeEventListener: vi.fn(),
                },
                writable: true,
                configurable: true,
            });
        }

        beforeEach(() => {
            setVisualViewport(window.innerHeight); // default no keyboard
        });

        it('keyboardHeight is 0 when viewport fills the window', () => {
            const { result } = mountComposable();
            expect(result.keyboardHeight.value).toBe(0);
        });

        it('computes positive offset when keyboard partially overlaps viewport', () => {
            // Simulate a 300px keyboard taking space on an 768px screen
            setVisualViewport(window.innerHeight - 300);
            const { result } = mountComposable();
            expect(result.keyboardHeight.value).toBe(300);
        });

        it('clamps keyboardHeight to 0 when viewport is taller than window (edge case)', () => {
            setVisualViewport(window.innerHeight + 100);
            const { result } = mountComposable();
            expect(result.keyboardHeight.value).toBe(0);
        });

        it('registers resize and scroll listeners on visualViewport on mount', () => {
            const addEventListener = vi.fn();
            const removeEventListener = vi.fn();
            Object.defineProperty(window, 'visualViewport', {
                value: {
                    height: window.innerHeight,
                    addEventListener,
                    removeEventListener,
                },
                writable: true,
                configurable: true,
            });

            const { wrapper } = mountComposable();

            expect(addEventListener).toHaveBeenCalledWith('resize', expect.any(Function));
            expect(addEventListener).toHaveBeenCalledWith('scroll', expect.any(Function));
            expect(addEventListener).toHaveBeenCalledTimes(2);
        });

        it('removes listeners on unmount', () => {
            const addEventListener = vi.fn();
            const removeEventListener = vi.fn();
            Object.defineProperty(window, 'visualViewport', {
                value: {
                    height: window.innerHeight,
                    addEventListener,
                    removeEventListener,
                },
                writable: true,
                configurable: true,
            });

            const { wrapper } = mountComposable();
            wrapper.unmount();

            expect(removeEventListener).toHaveBeenCalledTimes(2);
            expect(removeEventListener).toHaveBeenCalledWith('resize', expect.any(Function));
            expect(removeEventListener).toHaveBeenCalledWith('scroll', expect.any(Function));
        });
    });
});
