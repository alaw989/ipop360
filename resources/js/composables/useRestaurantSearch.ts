import { ref, nextTick, type Ref } from 'vue';
import type { Restaurant } from '@/types/restaurant';

type Phase = 'idle' | 'searching' | 'results' | 'empty' | 'error';

interface SearchParams {
    selectedCuisine?: string;
    selectedCategory?: string;
    lat: Ref<number | null>;
    lng: Ref<number | null>;
    sort: Ref<string>;
    distance?: number;
}

interface ApiResponse {
    data?: Restaurant[];
    next_page_url?: string | null;
}

export function useRestaurantSearch(
    setPhase: (phase: Phase) => void,
    getPhase: () => Phase
) {
    const restaurants = ref<Restaurant[]>([]);
    const shouldStagger = ref(false);
    const isResorting = ref(false);
    const isLoadingMore = ref(false);
    const nextPageUrl = ref<string | null>(null);
    const searchError = ref<string | null>(null);
    const loadMoreError = ref<string | null>(null);

    let currentRequestId = 0;
    let abortController: AbortController | null = null;

    function buildSearchParams(params: SearchParams): URLSearchParams {
        const query = new URLSearchParams();
        if (params.selectedCuisine) {
            query.set('cuisine', params.selectedCuisine);
        } else if (params.selectedCategory) {
            query.set('category', params.selectedCategory);
        }
        if (params.lat.value !== null) {
            query.set('lat', params.lat.value.toString());
        }
        if (params.lng.value !== null) {
            query.set('lng', params.lng.value.toString());
        }
        query.set('sort', params.sort.value);
        if (params.distance !== undefined) {
            query.set('distance', params.distance.toString());
        }
        return query;
    }

    async function fetchValidated(url: string, signal?: AbortSignal): Promise<ApiResponse> {
        const response = await fetch(url, { signal });
        if (!response.ok) {
            throw new Error('Search failed');
        }
        const data: unknown = await response.json();
        if (typeof data !== 'object' || data === null || !Array.isArray((data as ApiResponse).data)) {
            throw new Error('Invalid response');
        }
        return data as ApiResponse;
    }

    function abortPending(): void {
        if (abortController) {
            abortController.abort();
        }
        abortController = new AbortController();
    }

    async function search(params: SearchParams): Promise<void> {
        abortPending();
        isLoadingMore.value = false;

        const id = ++currentRequestId;
        setPhase('searching');
        searchError.value = null;
        loadMoreError.value = null;

        const query = buildSearchParams(params);

        try {
            const data = await fetchValidated(`/api/restaurants?${query}`, abortController!.signal);
            if (id !== currentRequestId) return;

            restaurants.value = data.data ?? [];
            nextPageUrl.value = data.next_page_url ?? null;

            if (restaurants.value.length === 0) {
                setPhase('empty');
            } else {
                shouldStagger.value = true;
                setPhase('results');
                nextTick(() => {
                    shouldStagger.value = false;
                });
            }
            searchError.value = null;
        } catch (error: unknown) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            if (id !== currentRequestId) return;

            searchError.value = 'Couldn\'t reach the listing service. Please try again.';
            restaurants.value = [];
            nextPageUrl.value = null;
            setPhase('error');
        }
    }

    async function resort(params: SearchParams): Promise<void> {
        const currentPhase = getPhase();
        if (currentPhase !== 'results' && currentPhase !== 'empty') {
            return search(params);
        }

        abortPending();
        isLoadingMore.value = false;

        const id = ++currentRequestId;
        isResorting.value = true;
        searchError.value = null;
        loadMoreError.value = null;

        const query = buildSearchParams(params);

        try {
            const data = await fetchValidated(`/api/restaurants?${query}`, abortController!.signal);
            if (id !== currentRequestId) return;

            restaurants.value = data.data ?? [];
            nextPageUrl.value = data.next_page_url ?? null;
            if (restaurants.value.length === 0) {
                setPhase('empty');
            } else {
                setPhase('results');
            }
        } catch (error: unknown) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            if (id !== currentRequestId) return;

            searchError.value = 'Couldn\'t reach the listing service. Please try again.';
            restaurants.value = [];
            nextPageUrl.value = null;
            setPhase('error');
        } finally {
            isResorting.value = false;
        }
    }

    async function loadMore(): Promise<void> {
        if (!nextPageUrl.value || getPhase() !== 'results' || isLoadingMore.value) return;

        isLoadingMore.value = true;
        const id = ++currentRequestId;
        const signal = abortController?.signal;

        try {
            const data = await fetchValidated(nextPageUrl.value, signal);
            if (id !== currentRequestId) return;

            restaurants.value.push(...(data.data ?? []));
            nextPageUrl.value = data.next_page_url ?? null;
            loadMoreError.value = null;
        } catch (error: unknown) {
            if (error instanceof DOMException && error.name === 'AbortError') return;
            if (id !== currentRequestId) return;

            loadMoreError.value = 'Couldn\'t load more results. Please try again.';
        } finally {
            isLoadingMore.value = false;
        }
    }

    function resetState(): void {
        if (abortController) {
            abortController.abort();
            abortController = null;
        }
        currentRequestId = 0;
        restaurants.value = [];
        nextPageUrl.value = null;
        searchError.value = null;
        loadMoreError.value = null;
        shouldStagger.value = false;
        isResorting.value = false;
        isLoadingMore.value = false;
    }

    return {
        restaurants,
        shouldStagger,
        isResorting,
        isLoadingMore,
        nextPageUrl,
        searchError,
        loadMoreError,
        search,
        resort,
        loadMore,
        resetState,
    };
}
