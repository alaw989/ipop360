import '../css/app.css';
import './bootstrap';
import '@fontsource/poppins';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, defineAsyncComponent, DefineComponent, h, Transition } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { router } from '@inertiajs/vue3';
import { useSearchLoadingOverlay } from './composables/useSearchLoadingOverlay';
import { mergeFavoritesOnLogin } from './lib/mergeFavoritesOnLogin';

// Lazy: only fetched once a search is actually triggered, so it isn't part
// of every page's entry chunk (bundle diet, matches spec-061's precedent).
const SearchLoadingOverlay = defineAsyncComponent(() => import('./Components/SearchLoadingOverlay.vue'));

const appName = import.meta.env["VITE_APP_NAME"] || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        // Check for merge-on-login on initial page load
        mergeFavoritesOnLogin(props.initialPage.props);

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
    mergeFavoritesOnLogin(event.detail.page.props);
});
