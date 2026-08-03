import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: 'resources/js/app.ts',
            ssr: 'resources/js/ssr.ts',
            refresh: true,
        }),
        tailwindcss(),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    build: {
        rollupOptions: {
            output: {
                // Split stable framework deps (Vue + Inertia) into a long-cached
                // 'vendor' chunk so app-code changes don't bust it (spec-061).
                manualChunks(id) {
                    if (
                        id.includes('node_modules/vue/')
                        || id.includes('node_modules/@vue/')
                        || id.includes('node_modules/@inertiajs/')
                    ) {
                        return 'vendor';
                    }
                },
            },
        },
    },
    ssr: {
        // Bundle the SSR deps INTO bootstrap/ssr/ssr.js so it runs without
        // node_modules on the droplet (deploy rsync excludes node_modules).
        // Without this, `node bootstrap/ssr/ssr.js` throws ERR_MODULE_NOT_FOUND
        // on @inertiajs/vue3 and the supervisor process crash-loops. Inertia
        // falls back to CSR meanwhile, so a broken/stale bundle can never take
        // the site down. (spec-063)
        // @inertiajs/vue3 v2 pulls in @inertiajs/core which transitively imports
        // lodash-es; listing packages one-by-one leaks more each upgrade. Bundle
        // EVERYTHING into bootstrap/ssr/ssr.js so it runs without node_modules on
        // the droplet (deploy rsync excludes node_modules). Inertia falls back to
        // CSR if a broken/stale bundle ever appears, so the site can't go down.
        noExternal: true,
    },
});
