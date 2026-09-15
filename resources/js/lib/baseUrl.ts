/**
 * SSR-safe origin resolution, shared by useBaseUrl() (SEO canonicals) and
 * lib/api's getBaseUrl() (API fetches).
 *
 * Client: the real browser origin — correct on localhost/staging/prod.
 * SSR: the shared `seo.base_url` Inertia prop (config('app.url') on the PHP
 * side, spec-115), falling back to the production origin only when no page
 * context is available (e.g. a bare `node` test with no Inertia app).
 */

import { usePage } from '@inertiajs/vue3';

export const PRODUCTION_ORIGIN = 'https://ipop360.com';

export function resolveBaseUrl(): string {
    if (typeof window !== 'undefined') {
        return `${window.location.protocol}//${window.location.host}`;
    }

    try {
        const shared = (usePage().props as { seo?: { base_url?: string } } | null | undefined)?.seo?.base_url;

        if (shared && shared !== '') {
            return shared;
        }
    } catch {
        // No Inertia page context (unit tests, unusual SSR call sites).
    }

    return PRODUCTION_ORIGIN;
}
