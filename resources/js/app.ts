import '../css/app.css';
import './bootstrap';
// Poppins 600/700 for headings and restaurant names, Source Sans 3 (variable,
// every weight in one file) for everything else. Each file declares all its
// subsets behind unicode-range, so a page downloads only the ones its text
// uses (Latin, unless a name like "Phở" needs more). Only Poppins 400 used to
// load, so every bold on the site was faked by the browser.
import '@fontsource/poppins/600.css';
import '@fontsource/poppins/700.css';
import '@fontsource-variable/source-sans-3/wght.css';
import '@fontsource-variable/source-sans-3/wght-italic.css';

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
