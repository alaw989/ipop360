// @vitest-environment node
// Exercises the `typeof window === 'undefined'` branch of the SSR-safe base
// URL helpers — the guard that keeps spec-063's Inertia SSR from crashing.
// Runs in the node env (no window); every other test file uses jsdom.
//
// spec-115: the fallback order is shared `seo.base_url` Inertia prop →
// production origin. Without any page context the production origin stands.
import { describe, it, expect, vi } from 'vitest';

const pageProps: { seo?: { base_url?: string } } = {};
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));

import { useBaseUrl } from '@/composables/useBaseUrl';
import { getBaseUrl } from '@/lib/api';

describe('SSR fallback (no window)', () => {
    it('useBaseUrl falls back to the production origin', () => {
        expect(useBaseUrl().value).toBe('https://ipop360.com');
    });

    it('getBaseUrl falls back to the production origin', () => {
        expect(getBaseUrl()).toBe('https://ipop360.com');
    });

    it('both honor the shared seo.base_url prop when present', () => {
        pageProps.seo = { base_url: 'https://staging.example.test' };

        try {
            expect(useBaseUrl().value).toBe('https://staging.example.test');
            expect(getBaseUrl()).toBe('https://staging.example.test');
        } finally {
            delete pageProps.seo;
        }
    });
});
