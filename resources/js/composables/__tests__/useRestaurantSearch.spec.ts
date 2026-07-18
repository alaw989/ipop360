import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import type { Restaurant } from '@/types/restaurant';
import { useRestaurantSearch } from '@/composables/useRestaurantSearch';

type Phase = 'idle' | 'searching' | 'results' | 'empty' | 'error';

function makeVenue(overrides: Partial<Restaurant> = {}): Restaurant {
    return {
        id: 1,
        name: 'Test Restaurant',
        slug: 'test-restaurant',
        description: null,
        address: '123 Main St',
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

function mockOkResponse(data: Restaurant[], nextPageUrl?: string | null) {
    return Promise.resolve({
        ok: true,
        json: async () => ({ data, next_page_url: nextPageUrl ?? null }),
    });
}

function mockErrorResponse(status = 500) {
    return Promise.resolve({
        ok: false,
        status,
        json: async () => ({ message: 'Server error' }),
    });
}

function setup() {
    let phase: Phase = 'idle';
    const setPhase = vi.fn((p: Phase) => { phase = p; });
    const getPhase = vi.fn(() => phase);
    const composable = useRestaurantSearch(setPhase, getPhase);
    return { ...composable, setPhase, getPhase, currentPhase: () => phase };
}

function setupWithPhase(initial: Phase) {
    let phase: Phase = initial;
    const setPhase = vi.fn((p: Phase) => { phase = p; });
    const getPhase = vi.fn(() => phase);
    const composable = useRestaurantSearch(setPhase, getPhase);
    return { ...composable, setPhase, getPhase, currentPhase: () => phase };
}

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('search()', () => {
    const params = {
        selectedCuisine: 'mexican',
        lat: { value: 30.27 },
        lng: { value: -97.74 },
        sort: { value: 'best_match' },
    };

    it('clears shouldStagger after search completes (arms during render, cleans up via nextTick)', async () => {
        const { search, shouldStagger } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockOkResponse([makeVenue()]),
        );

        await search(params);

        expect(shouldStagger.value).toBe(false);
    });

    it('sets phase to searching then results on success', async () => {
        const { search, setPhase } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockOkResponse([makeVenue()]),
        );

        await search(params);

        expect(setPhase).toHaveBeenCalledWith('searching');
        expect(setPhase).toHaveBeenCalledWith('results');
    });

    it('sets phase to empty when no results', async () => {
        const { search, setPhase, currentPhase } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockOkResponse([]),
        );

        await search(params);

        expect(setPhase).toHaveBeenCalledWith('empty');
        expect(currentPhase()).toBe('empty');
    });

    it('sets error state on non-ok response', async () => {
        const { search, searchError, currentPhase } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockErrorResponse(),
        );

        await search(params);

        expect(searchError.value).toBe('Couldn\'t reach the listing service. Please try again.');
        expect(currentPhase()).toBe('error');
    });

    it('sets error state on network failure', async () => {
        const { search, searchError, currentPhase } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(
            new TypeError('Failed to fetch'),
        );

        await search(params);

        expect(searchError.value).toBe('Couldn\'t reach the listing service. Please try again.');
        expect(currentPhase()).toBe('error');
    });

    it('sets error state on malformed 200 (missing data.data)', async () => {
        const { search, searchError, currentPhase } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            Promise.resolve({
                ok: true,
                json: async () => ({ error: 'something' }),
            }),
        );

        await search(params);

        expect(searchError.value).toBe('Couldn\'t reach the listing service. Please try again.');
        expect(currentPhase()).toBe('error');
    });

    it('stale response race: a slow prior search does NOT overwrite a fresh one', async () => {
        let resolveSlow!: (value: unknown) => void;
        const slowPromise = new Promise((resolve) => { resolveSlow = resolve; });

        const { search, restaurants } = setup();
        const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;

        fetchMock.mockReturnValueOnce(slowPromise);
        const firstSearch = search(params);

        fetchMock.mockReturnValueOnce(mockOkResponse(
            [makeVenue({ id: 2, name: 'Fast Search' })],
        ));
        await search(params);

        expect(restaurants.value).toHaveLength(1);
        expect(restaurants.value[0].name).toBe('Fast Search');

        resolveSlow(mockOkResponse([makeVenue({ id: 1, name: 'Slow Search' })]));
        await firstSearch;

        expect(restaurants.value).toHaveLength(1);
        expect(restaurants.value[0].name).toBe('Fast Search');
    });
});

describe('resort()', () => {
    const params = {
        selectedCuisine: 'mexican',
        lat: { value: 30.27 },
        lng: { value: -97.74 },
        sort: { value: 'rating' },
    };

    it('sets isResorting and updates results without phase change to searching', async () => {
        const { resort: r, isResorting: ir, restaurants, setPhase: sp } = setupWithPhase('results');

        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockOkResponse([makeVenue({ id: 3, name: 'Sorted' })]),
        );

        await r(params);

        expect(ir.value).toBe(false);
        expect(restaurants.value[0].name).toBe('Sorted');
        expect(sp).not.toHaveBeenCalledWith('searching');
    });

    it('falls back to search when not in results phase', async () => {
        const { resort, setPhase, restaurants, currentPhase } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockOkResponse([makeVenue()]),
        );

        await resort(params);

        expect(setPhase).toHaveBeenCalledWith('searching');
        expect(restaurants.value).toHaveLength(1);
    });

    it('handles errors gracefully', async () => {
        let phase: Phase = 'results';
        const sp = vi.fn((p: Phase) => { phase = p; });
        const gp = vi.fn(() => phase);
        const { resort: r, searchError } = useRestaurantSearch(sp, gp);

        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockErrorResponse(),
        );

        await r(params);

        expect(searchError.value).toBe('Couldn\'t reach the listing service. Please try again.');
    });
});

describe('loadMore()', () => {
    it('appends results and updates nextPageUrl', async () => {
        let phase: Phase = 'results';
        const sp = vi.fn((p: Phase) => { phase = p; });
        const gp = vi.fn(() => phase);
        const { loadMore, restaurants, nextPageUrl, loadMoreError } = useRestaurantSearch(sp, gp);

        restaurants.value = [makeVenue({ id: 1 })];
        nextPageUrl.value = '/api/restaurants?page=2';

        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockOkResponse([makeVenue({ id: 2, name: 'Page 2' })], '/api/restaurants?page=3'),
        );

        await loadMore();

        expect(restaurants.value).toHaveLength(2);
        expect(restaurants.value[1].name).toBe('Page 2');
        expect(nextPageUrl.value).toBe('/api/restaurants?page=3');
        expect(loadMoreError.value).toBeNull();
    });

    it('returns early when nextPageUrl is null', async () => {
        const { loadMore, restaurants } = setup();
        restaurants.value = [makeVenue()];
        const fetchSpy = globalThis.fetch as ReturnType<typeof vi.fn>;

        await loadMore();

        expect(restaurants.value).toHaveLength(1);
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it('returns early when already loading', async () => {
        let resolveLoad!: (value: unknown) => void;
        const loadPromise = new Promise((resolve) => { resolveLoad = resolve; });

        let phase: Phase = 'results';
        const sp = vi.fn((p: Phase) => { phase = p; });
        const gp = vi.fn(() => phase);
        const { loadMore, restaurants, nextPageUrl } = useRestaurantSearch(sp, gp);

        restaurants.value = [makeVenue({ id: 1 })];
        nextPageUrl.value = '/api/restaurants?page=2';

        const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
        fetchMock.mockReturnValue(loadPromise);

        const firstLoad = loadMore();
        const secondResult = loadMore();

        resolveLoad(mockOkResponse([makeVenue({ id: 2 })], null));
        await firstLoad;
        await secondResult;

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('handles errors gracefully', async () => {
        let phase: Phase = 'results';
        const sp = vi.fn((p: Phase) => { phase = p; });
        const gp = vi.fn(() => phase);
        const { loadMore, restaurants, nextPageUrl, loadMoreError } = useRestaurantSearch(sp, gp);

        restaurants.value = [makeVenue({ id: 1 })];
        nextPageUrl.value = '/api/restaurants?page=2';

        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue(
            mockErrorResponse(),
        );

        await loadMore();

        expect(loadMoreError.value).toBe('Couldn\'t load more results. Please try again.');
        expect(restaurants.value).toHaveLength(1);
    });
});

describe('abort and request-id guards', () => {
    it('a new search aborts a pending loadMore (cursor does not leak)', async () => {
        let resolveLoad!: (value: unknown) => void;
        const loadPromise = new Promise((resolve) => { resolveLoad = resolve; });

        let phase: Phase = 'results';
        const sp = vi.fn((p: Phase) => { phase = p; });
        const gp = vi.fn(() => phase);
        const { loadMore, search, restaurants, nextPageUrl, loadMoreError } = useRestaurantSearch(sp, gp);

        restaurants.value = [makeVenue({ id: 1 })];
        nextPageUrl.value = '/api/restaurants?page=2';

        const fetchMock = globalThis.fetch as ReturnType<typeof vi.fn>;
        fetchMock.mockReturnValueOnce(loadPromise);
        const loadPromiseResult = loadMore();

        fetchMock.mockResolvedValue(mockOkResponse([makeVenue({ id: 3, name: 'New Search' })]));
        await search({
            selectedCuisine: 'italian',
            lat: { value: 30.27 },
            lng: { value: -97.74 },
            sort: { value: 'best_match' },
        });

        resolveLoad(mockOkResponse([makeVenue({ id: 2 })], '/api/restaurants?page=3'));
        await loadPromiseResult;

        expect(restaurants.value[0].name).toBe('New Search');
        expect(restaurants.value).toHaveLength(1);
        expect(loadMoreError.value).toBeNull();
    });

    it('resetState aborts pending requests and clears request counter', async () => {
        let resolveSearch!: (value: unknown) => void;
        const searchPromise = new Promise((resolve) => { resolveSearch = resolve; });

        const { search, resetState, restaurants, searchError } = setup();
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockReturnValue(searchPromise);

        const searchResult = search({
            selectedCuisine: 'mexican',
            lat: { value: 30.27 },
            lng: { value: -97.74 },
            sort: { value: 'best_match' },
        });

        resetState();

        resolveSearch(mockOkResponse([makeVenue()]));
        await searchResult;

        expect(restaurants.value).toHaveLength(0);
        expect(searchError.value).toBeNull();
    });
});
