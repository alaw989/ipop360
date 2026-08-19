import { defineConfig, devices } from '@playwright/test'

// Mobile E2E pass. Requires the local stack running:
//   php artisan serve --port=8090  (or `composer dev`) + Vite dev server
// Reuses an already-running server on :8090 — run `npm run test:e2e`.
export default defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? 'github' : 'list',
    use: {
        baseURL: 'http://localhost:8090',
        trace: 'on-first-retry',
    },
    projects: [
        {
            name: 'mobile-chromium',
            use: {
                ...devices['iPhone 12'],
                browserName: 'chromium',
            },
        },
    ],
    webServer: {
        command: 'php artisan serve --port=8090',
        url: 'http://localhost:8090',
        reuseExistingServer: true,
        timeout: 60_000,
    },
})
