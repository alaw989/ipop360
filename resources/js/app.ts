import '../css/app.css';
import './bootstrap';
import '@fontsource/poppins';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, defineAsyncComponent, DefineComponent, h, Transition } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { useSearchLoadingOverlay } from './composables/useSearchLoadingOverlay';

// Lazy: only fetched once a search is actually triggered, so it isn't part
// of every page's entry chunk (bundle diet, matches spec-061's precedent).
const SearchLoadingOverlay = defineAsyncComponent(() => import('./Components/SearchLoadingOverlay.vue'));

const appName = import.meta.env["VITE_APP_NAME"] || 'Laravel';

// Merge-on-login: check if user has localStorage favorites to merge
const STORAGE_KEY = 'ipop360_favorites';
let hasCheckedMerge = false;

function checkAndMergeFavorites(pageProps: any) {
    if (hasCheckedMerge) return;

    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (!stored) return;

        const localFavorites = JSON.parse(stored);
        if (!Array.isArray(localFavorites) || localFavorites.length === 0) return;

        // Check if user is now authenticated
        const isAuthed = !!pageProps?.auth?.user;

        if (isAuthed) {
            // Prepare data for merge
            const ids: number[] = [];
            const venues: any[] = [];

            for (const fav of localFavorites) {
                if (fav.id && fav.id > 0) {
                    ids.push(fav.id);
                } else {
                    venues.push(fav.venue);
                }
            }

            // Mark as checked before the async call to prevent duplicate calls
            hasCheckedMerge = true;

            // Plain axios, not router.post: this is a background write, not a
            // page visit, and the endpoint returns JSON — Inertia's router
            // throws a fatal "must receive a valid Inertia response" error
            // (and takes over the whole page) on any non-Inertia response.
            axios
                .post('/favorites/merge', { ids, venues })
                .then(() => {
                    // Clear localStorage on successful merge
                    localStorage.removeItem(STORAGE_KEY);
                })
                .catch(() => {
                    // Reset on error so we can retry
                    hasCheckedMerge = false;
                    console.error('Failed to merge favorites');
                });
        }
    } catch (e) {
        console.error('Failed to check/merge favorites', e);
    }
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // Check for merge-on-login on initial page load
        checkAndMergeFavorites(props.initialPage.props);

        createApp({
            setup() {
                // Hosted at the persistent app root — not inside a page
                // component — so its fade-out can actually play even when the
                // search that triggered it finishes by swapping the current
                // page for a new one (see useSearchLoadingOverlay).
                const { isVisible } = useSearchLoadingOverlay();
                return () => [
                    h(Transition, { name: 'search-overlay' }, () => (isVisible.value ? h(SearchLoadingOverlay) : null)),
                    h(App, props),
                ];
            },
        })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#f59e0b',
        includeCSS: true,
        showSpinner: false,
    },
});

// Also check on each navigation (for login redirect scenarios)
router.on('success', (event) => {
    checkAndMergeFavorites(event.detail.page.props);
});
