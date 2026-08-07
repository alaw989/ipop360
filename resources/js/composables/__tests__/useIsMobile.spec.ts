import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ref } from 'vue';
import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { useIsMobile } from '@/composables/useIsMobile';

const mockMediaQueryRef = ref(false);
vi.mock('@vueuse/core', () => ({
    useMediaQuery: vi.fn(() => mockMediaQueryRef),
}));

function mountComposable() {
    let result: ReturnType<typeof useIsMobile> | null = null;
    const wrapper = mount(
        defineComponent({
            setup() {
                result = useIsMobile();
                return () => h('div');
            },
        }),
    );
    return { wrapper, result: result! };
}

describe('useIsMobile', () => {
    beforeEach(() => {
        mockMediaQueryRef.value = false;
    });

    it('returns an isMobile ref', () => {
        const { result } = mountComposable();
        expect(result.isMobile).toBe(mockMediaQueryRef);
        expect(result.isMobile.value).toBe(false);
    });

    it('reflects mobile state when media query matches', () => {
        mockMediaQueryRef.value = true;
        const { result } = mountComposable();
        expect(result.isMobile.value).toBe(true);
    });

    it('delegates to useMediaQuery with max-width: 767px', async () => {
        const core = await import('@vueuse/core');
        mountComposable();
        expect(vi.mocked(core.useMediaQuery)).toHaveBeenCalledWith('(max-width: 767px)');
    });
});
