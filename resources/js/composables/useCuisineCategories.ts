import { ref, type Ref } from 'vue';

export interface SearchCuisine {
    id: number;
    name: string;
    slug: string;
    icon: string | null;
}

export interface SearchCategory {
    id: number;
    name: string;
    slug: string;
    icon: string | null;
    cuisines: SearchCuisine[];
}

// One request per page load, shared by every search bar on the page (Inertia
// remounts the header on each visit, so the list lives at module scope).
let pending: Promise<SearchCategory[]> | null = null;
const loaded = ref<SearchCategory[]>([]);

/**
 * The full cuisine list for the header search, fetched once from
 * /api/cuisine-categories. A failed request leaves the list empty and is
 * retried on the next call.
 */
export function useCuisineCategories(): { categories: Ref<SearchCategory[]>; load: () => Promise<SearchCategory[]> } {
    function load(): Promise<SearchCategory[]> {
        if (loaded.value.length > 0) return Promise.resolve(loaded.value);
        pending ??= fetch('/api/cuisine-categories', { headers: { Accept: 'application/json' } })
            .then((res) => (res.ok ? (res.json() as Promise<SearchCategory[]>) : []))
            .then((list) => {
                loaded.value = Array.isArray(list) ? list : [];
                return loaded.value;
            })
            .catch(() => [] as SearchCategory[])
            .finally(() => {
                pending = null;
            });

        return pending;
    }

    return { categories: loaded, load };
}
