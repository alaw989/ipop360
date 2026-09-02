import axios from 'axios';

const STORAGE_KEY = 'ipop360_favorites';

interface LocalFavorite {
    id?: number;
    venue?: unknown;
}

let hasCheckedMerge = false;

/**
 * Merge-on-login: if the user has localStorage favorites and is now
 * authenticated, POST them to /favorites/merge and clear localStorage on
 * success. Extracted from app.ts so the round-trip is testable in isolation
 * (app.ts was boot-time code that could not be unit-tested directly).
 *
 * The returned promise resolves once the merge attempt settles (success or
 * failure — errors are swallowed here, matching the original fire-and-forget
 * behavior, so app.ts never surfaces an unhandled rejection); early no-op
 * paths resolve immediately.
 */
export function mergeFavoritesOnLogin(pageProps: unknown): Promise<void> {
    if (hasCheckedMerge) return Promise.resolve();

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (!stored) return Promise.resolve();

        const localFavorites = JSON.parse(stored);
        if (!Array.isArray(localFavorites) || localFavorites.length === 0) return Promise.resolve();

        // Check if the user is now authenticated.
        const isAuthed = !!(pageProps as { auth?: { user?: unknown } })?.auth?.user;
        if (!isAuthed) return Promise.resolve();

        const ids: number[] = [];
        const venues: unknown[] = [];

        for (const fav of localFavorites as LocalFavorite[]) {
            if (fav.id && fav.id > 0) {
                ids.push(fav.id);
            } else {
                venues.push(fav.venue);
            }
        }

        // Mark checked before the async call to prevent duplicate merges.
        hasCheckedMerge = true;

        // Plain axios, not router.post: this is a background write, not a page
        // visit, and the endpoint returns JSON — Inertia's router throws a
        // fatal "must receive a valid Inertia response" error (and takes over
        // the whole page) on any non-Inertia response.
        return axios
            .post('/favorites/merge', { ids, venues })
            .then(() => {
                // Clear localStorage on successful merge.
                localStorage.removeItem(STORAGE_KEY);
            })
            .catch(() => {
                // Reset on error so we can retry.
                hasCheckedMerge = false;
                console.error('Failed to merge favorites');
            });
    } catch (e) {
        console.error('Failed to check/merge favorites', e);
        return Promise.resolve();
    }
}
