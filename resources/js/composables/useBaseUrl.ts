/**
 * SSR-safe base URL composable.
 * Provides the current origin (protocol + host) for SEO and canonical URLs.
 *
 * The server-rendered origin comes from the `seo.base_url` Inertia prop
 * (config('app.url') on the PHP side) instead of a hardcoded production
 * domain — a non-prod SSR render can no longer emit canonical URLs pointing
 * at ipop360.com (spec-115).
 */

import { computed } from 'vue';
import { resolveBaseUrl } from '@/lib/baseUrl';

/**
 * Get the base URL (protocol + host) for the current request.
 * Client: the actual browser origin (correct on localhost/staging/prod).
 * SSR: the shared `seo.base_url` prop, falling back to the production origin.
 */
export function useBaseUrl() {
    return computed(() => resolveBaseUrl());
}
