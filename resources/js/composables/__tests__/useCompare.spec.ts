import { describe, it, expect, beforeEach, vi } from 'vitest';
import type { Restaurant } from '@/types/restaurant';

const STORAGE_KEY = 'ipop360_compare_ids';

function makeVenue(overrides: Partial<Restaurant> = {}): Restaurant {
    return {
        id: 1,
        name: 'Casa Garcia',
        slug: 'casa-garcia',
        description: null,
        address: '123 Main',
        city: 'Austin',
        state: 'TX',
        lat: 30.27,
        lng: -97.74,
        photo_url: null,
        price_range: '$$',
        phone: '512-555-1212',
        website_url: null,
        google_rating: 4.5,
        google_review_count: 100,
        yelp_rating: null,
        yelp_review_count: 0,
        has_award: false,
        popularity_score: 0.7,
        distance: 1.2,
        cuisines: [],
        source: 'serpapi',
        ...overrides,
    };
}

function readStoredIds(): number[] {
    const raw = localStorage.getItem(STORAGE_KEY);
    return raw ? JSON.parse(raw) : [];
}

beforeEach(() => {
    localStorage.clear();
    vi.resetModules();
});

describe('useCompare', () => {
    it('starts empty', async () => {
        const { useCompare } = await import('@/composables/useCompare');
        const { count, isInCompare, compareUrl } = useCompare();

        expect(count.value).toBe(0);
        expect(isInCompare(makeVenue())).toBe(false);
        expect(compareUrl.value).toBeNull();
    });

    it('toggleCompare adds a venue to comparison and localStorage', async () => {
        const { useCompare } = await import('@/composables/useCompare');
        const { count, isInCompare, toggleCompare } = useCompare();
        const venue = makeVenue({ id: 1 });

        toggleCompare(venue);

        expect(count.value).toBe(1);
        expect(isInCompare(venue)).toBe(true);
        expect(readStoredIds()).toEqual([1]);
    });

    it('toggleCompare removes a venue when toggled twice', async () => {
        const { useCompare } = await import('@/composables/useCompare');
        const { count, isInCompare, toggleCompare } = useCompare();
        const venue = makeVenue({ id: 1 });

        toggleCompare(venue);
        toggleCompare(venue);

        expect(count.value).toBe(0);
        expect(isInCompare(venue)).toBe(false);
        expect(readStoredIds()).toEqual([]);
    });

    it('clearCompare removes all venues', async () => {
        const { useCompare } = await import('@/composables/useCompare');
        const { count, isInCompare, toggleCompare, clearCompare } = useCompare();

        toggleCompare(makeVenue({ id: 1 }));
        toggleCompare(makeVenue({ id: 2 }));
        expect(count.value).toBe(2);

        clearCompare();

        expect(count.value).toBe(0);
        expect(isInCompare(makeVenue({ id: 1 }))).toBe(false);
        expect(isInCompare(makeVenue({ id: 2 }))).toBe(false);
        expect(readStoredIds()).toEqual([]);
    });

    it('isInCompare distinguishes venues by id', async () => {
        const { useCompare } = await import('@/composables/useCompare');
        const { isInCompare, toggleCompare } = useCompare();

        toggleCompare(makeVenue({ id: 1 }));

        expect(isInCompare(makeVenue({ id: 1 }))).toBe(true);
        expect(isInCompare(makeVenue({ id: 2 }))).toBe(false);
    });

    it('compareUrl returns comma-separated ids when multiple venues', async () => {
        const { useCompare } = await import('@/composables/useCompare');
        const { compareUrl, toggleCompare } = useCompare();

        toggleCompare(makeVenue({ id: 1 }));
        toggleCompare(makeVenue({ id: 2 }));

        expect(compareUrl.value).toBe('/compare?ids=1,2');
    });

    it('compareUrl returns null when empty', async () => {
        const { useCompare } = await import('@/composables/useCompare');
        const { compareUrl } = useCompare();

        expect(compareUrl.value).toBeNull();
    });

    it('persists to localStorage and reloads across sessions', async () => {
        const { useCompare: useCompare1 } = await import('@/composables/useCompare');
        useCompare1().toggleCompare(makeVenue({ id: 1 }));
        useCompare1().toggleCompare(makeVenue({ id: 2 }));

        vi.resetModules();

        const { useCompare: useCompare2 } = await import('@/composables/useCompare');
        const { count, isInCompare } = useCompare2();

        expect(count.value).toBe(2);
        expect(isInCompare(makeVenue({ id: 1 }))).toBe(true);
        expect(isInCompare(makeVenue({ id: 2 }))).toBe(true);
    });

    it('handles corrupt localStorage gracefully', async () => {
        localStorage.setItem(STORAGE_KEY, 'not-json{garbage');

        const { useCompare } = await import('@/composables/useCompare');
        const { count, compareUrl } = useCompare();

        expect(count.value).toBe(0);
        expect(compareUrl.value).toBeNull();
    });
});
