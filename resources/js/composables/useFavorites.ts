import { computed, ref } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import axios from 'axios';
import type { Restaurant } from '@/types/restaurant';

const STORAGE_KEY = 'ipop360_favorites';

interface LocalFavorite {
    id?: number;
    key: string;
    venue: Restaurant;
}

interface PageProps {
    auth?: {
        user?: any;
        favorites?: number[];
    };
}

/**
 * Composable for managing restaurant favorites with hybrid persistence:
 * - Authed users: server-side with optimistic updates
 * - Guests: localStorage with login hint
 */
export function useFavorites() {
    const page = usePage();
    const authUser = computed(() => (page.props as PageProps).auth?.user);

    // Read initial state from props (authed) or localStorage (guest)
    const serverFavoriteIds = computed(() => {
        const favorites = (page.props as PageProps).auth?.favorites;
        return favorites ?? [];
    });

    const localFavorites = ref<LocalFavorite[]>([]);

    // Initialize from localStorage only on client
    if (typeof window !== 'undefined') {
        try {
            const stored = localStorage.getItem(STORAGE_KEY);
            if (stored) {
                localFavorites.value = JSON.parse(stored);
            }
        } catch {
            localFavorites.value = [];
        }
    }

    const localFavoriteKeys = computed(() =>
        new Set(localFavorites.value.map((f) => f.key))
    );

    // Per-restaurant promise chain: concurrent toggle() calls on the same venue
    // serialize, so the second reads the first's reconciled server state instead
    // of racing its optimistic write (which would base its rollback snapshot on
    // the already-mutated props).
    const inFlight = new Map<number, Promise<void>>();

    /**
     * Check if a restaurant is favorited.
     */
    function isFavorited(restaurant: Restaurant): boolean {
        if (authUser.value) {
            return serverFavoriteIds.value.includes(restaurant.id);
        }

        // For guests, check by unique key (google_place_id or slug fallback)
        const key = getFavoriteKey(restaurant);
        return localFavoriteKeys.value.has(key);
    }

    /**
     * Generate a unique key for a restaurant (used for localStorage dedup).
     * Prefers google_place_id, falls back to slug, then a generated key.
     */
    function getFavoriteKey(restaurant: Restaurant): string {
        if (restaurant.google_place_id) {
            return `gp:${restaurant.google_place_id}`;
        }
        if (restaurant.slug) {
            return `slug:${restaurant.slug}`;
        }
        // Fallback: use name+city for live results without slug
        return `name:${restaurant.name}:${restaurant.city || ''}`;
    }

    /**
     * Toggle a restaurant's favorite status.
     */
    async function toggle(restaurant: Restaurant) {
        if (authUser.value) {
            // Serialize toggles per restaurant: queue this call behind any
            // in-flight toggle on the same venue, then run the real mutation.
            const id = restaurant.id;
            const previous = inFlight.get(id) ?? Promise.resolve();
            const task = previous.then(() => toggleAuthed(restaurant));
            // Store a never-rejecting guard so a later toggle still runs even
            // if this one failed (a rejected chain would skip it entirely).
            const guarded = task.catch(() => undefined);
            inFlight.set(id, guarded);
            guarded.then(() => {
                if (inFlight.get(id) === guarded) inFlight.delete(id);
            });
            return task;
        } else {
            // Guest: localStorage toggle with login hint
            const key = getFavoriteKey(restaurant);
            const idx = localFavorites.value.findIndex((f) => f.key === key);

            if (idx >= 0) {
                // Remove
                localFavorites.value.splice(idx, 1);
            } else {
                // Add
                localFavorites.value.push({
                    id: restaurant.id,
                    key,
                    venue: restaurant,
                });
            }

            // Persist to localStorage
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify(localFavorites.value));
            } catch (e) {
                console.error('Failed to save favorites to localStorage', e);
            }

        }
    }

    /**
     * Authed server-side toggle with optimistic update. Runs after any
     * previously queued toggle on the same restaurant has settled.
     */
    async function toggleAuthed(restaurant: Restaurant): Promise<void> {
        // Authed: server-side toggle with optimistic update
        const wasFavorited = serverFavoriteIds.value.includes(restaurant.id);

        // Snapshot BEFORE the optimistic write — onError/catch must restore
        // THIS, not the (already-mutated) computed, or the rollback is a no-op.
        const previousFavorites = [...serverFavoriteIds.value];

        // Optimistic update
        const newFavorites = wasFavorited
            ? serverFavoriteIds.value.filter((id) => id !== restaurant.id)
            : [...serverFavoriteIds.value, restaurant.id];

        // Update props optimistically (will be reloaded from server on response)
        if ((page.props as PageProps).auth) {
            (page.props as PageProps).auth!.favorites = newFavorites;
        }

        try {
            // Plain axios, not router.post: this is a background write, not
            // a page visit, and the endpoint returns JSON — Inertia's router
            // throws a fatal "must receive a valid Inertia response" error
            // (and takes over the whole page) on any non-Inertia response.
            const response = await axios.post('/favorites/toggle', {
                restaurant,
                id: restaurant.id,
            });

            // Reconcile with the true server state now that we can read it.
            if ((page.props as PageProps).auth && Array.isArray(response.data?.favoriteIds)) {
                (page.props as PageProps).auth!.favorites = response.data.favoriteIds;
            }
        } catch (error) {
            // Rollback on network error
            if ((page.props as PageProps).auth) {
                (page.props as PageProps).auth!.favorites = previousFavorites;
            }

            // Session expired: roll back AND bounce to the login page so the
            // user can re-authenticate instead of silently swallowing it.
            const status = (error as { response?: { status?: number } })?.response?.status;
            if (status === 401) {
                router.visit('/login');
            }
            throw error;
        }
    }

    /**
     * Get the current list of favorite restaurants for merge-on-login.
     */
    function getLocalFavoritesForMerge(): {
        ids: number[];
        venues: Restaurant[];
    } {
        const ids: number[] = [];
        const venues: Restaurant[] = [];

        for (const fav of localFavorites.value) {
            if (fav.id && fav.id > 0) {
                ids.push(fav.id);
            } else {
                venues.push(fav.venue);
            }
        }

        return { ids, venues };
    }

    /**
     * Clear local favorites (called after successful merge on login).
     */
    function clearLocalFavorites(): void {
        localFavorites.value = [];
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            console.error('Failed to clear favorites from localStorage', e);
        }
    }

    /**
     * Check if there are local favorites to merge.
     */
    function hasLocalFavorites(): boolean {
        return localFavorites.value.length > 0;
    }

    return {
        isFavorited,
        toggle,
        getLocalFavoritesForMerge,
        clearLocalFavorites,
        hasLocalFavorites,
        serverFavoriteIds,
    };
}
