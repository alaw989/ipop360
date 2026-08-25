import { useDelayedLoading } from './useDelayedLoading'

let instance: ReturnType<typeof useDelayedLoading> | null = null

/**
 * Shared singleton for the homepage search-loading overlay. Must be a
 * singleton hosted outside any page component: Inertia swaps the current
 * page component (e.g. Welcome.vue → Search.vue) *before* firing `onFinish`,
 * so if the overlay's state lived inside the page that triggered the search,
 * it would already be torn down by the time `end()` runs — the fade-out
 * transition would never get a chance to play. The app root (see app.ts)
 * calls this once and renders the overlay there, so it survives the swap.
 */
export function useSearchLoadingOverlay() {
    if (!instance) {
        instance = useDelayedLoading()
    }
    return instance
}
