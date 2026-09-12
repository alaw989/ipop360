import { describe, it, expect, vi, beforeEach } from 'vitest';

const STORAGE_KEY = 'ipop360_favorites';

// Hoisted so the vi.mock factory (which runs before imports) closes over a
// stable mock, surviving the per-test vi.resetModules() used to reset the
// module-level hasCheckedMerge dedup flag.
const axiosMock = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('axios', () => ({ default: { post: (...args: unknown[]) => axiosMock.post(...args) } }));

// Each test gets a fresh module instance so the module-level hasCheckedMerge
// dedup flag resets (a second call would otherwise short-circuit as a no-op).
async function loadFresh() {
    vi.resetModules();
    return await import('@/lib/mergeFavoritesOnLogin');
}

// Venue shape matches what FavoriteController::merge() validates:
// venues.*.name (required) + venues.*.slug (nullable). Extra Restaurant fields
// are dropped by the controller's validate() ruleset, so a minimal fixture is
// faithful to the wire contract.
const authedProps = { auth: { user: { id: 1 } } };
const persistedVenue = { name: 'Casa Garcia', slug: 'casa-garcia' };
const unpersistedVenue = { name: 'Vivo', slug: 'vivo' };

beforeEach(() => {
    localStorage.clear();
    axiosMock.post.mockReset();
    axiosMock.post.mockResolvedValue({ data: { favoriteIds: [] } });
});

describe('mergeFavoritesOnLogin', () => {
    it('POSTs split {ids, venues} and clears localStorage on success', async () => {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify([
                { id: 5, key: 'slug:casa-garcia', venue: persistedVenue },
                { id: -7, key: 'slug:vivo', venue: unpersistedVenue },
            ]),
        );

        const { mergeFavoritesOnLogin } = await loadFresh();
        await mergeFavoritesOnLogin(authedProps);

        expect(axiosMock.post).toHaveBeenCalledWith('/favorites/merge', {
            ids: [5],
            venues: [unpersistedVenue],
        });
        expect(localStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('keeps localStorage when the POST rejects (retryable)', async () => {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify([{ id: -7, key: 'slug:vivo', venue: unpersistedVenue }]),
        );
        axiosMock.post.mockRejectedValue(new Error('Network error'));

        const { mergeFavoritesOnLogin } = await loadFresh();
        await mergeFavoritesOnLogin(authedProps);

        expect(axiosMock.post).toHaveBeenCalledWith('/favorites/merge', {
            ids: [],
            venues: [unpersistedVenue],
        });
        expect(localStorage.getItem(STORAGE_KEY)).not.toBeNull();
    });

    it('no-ops (no POST, storage kept) when the user is not authenticated', async () => {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify([{ id: 5, key: 'slug:casa-garcia', venue: persistedVenue }]),
        );

        const { mergeFavoritesOnLogin } = await loadFresh();
        await mergeFavoritesOnLogin({});

        expect(axiosMock.post).not.toHaveBeenCalled();
        expect(localStorage.getItem(STORAGE_KEY)).not.toBeNull();
    });
});
