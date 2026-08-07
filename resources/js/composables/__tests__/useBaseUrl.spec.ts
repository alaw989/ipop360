import { describe, it, expect } from 'vitest';
import { useBaseUrl } from '@/composables/useBaseUrl';

describe('useBaseUrl (client)', () => {
    it('returns window.location origin', () => {
        const baseUrl = useBaseUrl();
        expect(baseUrl.value).toBe('http://localhost:3000');
    });

    it('is reactive via computed', () => {
        expect(useBaseUrl()).toHaveProperty('value');
    });

    it('starts with http protocol', () => {
        const baseUrl = useBaseUrl();
        expect(baseUrl.value).toMatch(/^https?:\/\/[^/]+$/);
    });
});
