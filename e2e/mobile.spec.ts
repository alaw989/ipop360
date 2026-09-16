import { test, expect, type Page } from '@playwright/test'

// Mobile UX E2E pass (goal #11). Runs against the local dev stack on :8090 with
// an iPhone-class touch viewport. Covers the mobile-only affordances added by
// the redesign: the nav drawer, the search filter sheet + map/list toggle, and
// the sticky restaurant action bar. The detail page uses the deterministic
// E2ESeeder fixture (phone + website + coordinates).

const BASE = 'http://localhost:8090'

// Deterministic fixture from database/seeders/E2ESeeder.php — the detail page
// needs a restaurant with phone + website + coordinates so the sticky action
// bar renders all three actions. Real seed data was removed in spec-019.
// Keep in sync with E2ESeeder::RESTAURANT_SLUG.
const FIXTURE_SLUG = 'e2e-fixture-kitchen'

// `networkidle` is discouraged and flaky here: real dev data loads third-party
// restaurant photos and the PHP dev server can queue under parallel workers, so
// it may never settle. Waiting for the app shell to render is a stable signal.
async function gotoAndSettle(page: Page, path: string) {
    await page.goto(BASE + path)
    await page.waitForLoadState('domcontentloaded')
    await page.locator('main').first().waitFor({ state: 'visible' })
    await page.waitForTimeout(200)
}

async function gotoHome(page: Page) {
    await gotoAndSettle(page, '/')
}

test.describe('TopNav mobile drawer', () => {
    test('opens and closes the navigation drawer', async ({ page }) => {
        await gotoHome(page)

        await page.getByTestId('menu-toggle').click()
        await expect(page.getByTestId('mobile-menu')).toBeVisible()
        await expect(page.getByTestId('mobile-menu')).toContainText('Browse')
        await expect(page.getByTestId('mobile-menu')).toContainText('Leaderboard')
        await expect(page.getByTestId('mobile-menu')).toContainText('Blog')

        await page.getByTestId('mobile-menu-close').click()
        await expect(page.getByTestId('mobile-menu')).toBeHidden()
    })

    test('Escape closes the drawer', async ({ page }) => {
        await gotoHome(page)

        await page.getByTestId('menu-toggle').click()
        await expect(page.getByTestId('mobile-menu')).toBeVisible()

        await page.keyboard.press('Escape')
        await expect(page.getByTestId('mobile-menu')).toBeHidden()
    })
})

test.describe('Search mobile controls', () => {
    test.beforeEach(async ({ page }) => {
        await gotoAndSettle(page, '/search')
    })

    test('opens and closes the filter bottom sheet', async ({ page }) => {
        await page.getByTestId('mobile-filter-toggle').click()
        const sheet = page.getByRole('dialog')
        await expect(sheet).toBeVisible()
        await expect(sheet).toContainText('Filters')
        await expect(page.getByTestId('filter-close')).toBeVisible()

        // Close via Escape — deterministic under touch emulation (reka-ui
        // Dialog handles Escape; clicking the close button hit-tests against the
        // moving panel/overlay during the slide-in transition).
        await page.keyboard.press('Escape')
        await expect(sheet).toBeHidden()
    })

    test('toggles between list and map view', async ({ page }) => {
        await expect(page.getByTestId('mobile-map')).toBeHidden()
        await expect(page.getByText(/results/).first()).toBeVisible()

        await page.getByTestId('mobile-map-toggle').click()
        await expect(page.getByTestId('mobile-map')).toBeVisible()
        await expect(page.getByTestId('mobile-map-toggle')).toContainText('List')

        await page.getByTestId('mobile-map-toggle').click()
        await expect(page.getByTestId('mobile-map')).toBeHidden()
        await expect(page.getByTestId('mobile-map-toggle')).toContainText('Map')
    })

    test('map and filter toggles are hidden on desktop-width viewports', async ({ browser }) => {
        const desktop = await browser.newContext({
            viewport: { width: 1440, height: 900 },
        })
        const page = await desktop.newPage()
        await gotoAndSettle(page, '/search')

        await expect(page.getByTestId('mobile-filter-toggle')).toBeHidden()
        await expect(page.getByTestId('mobile-map-toggle')).toBeHidden()
        await desktop.close()
    })
})

test.describe('Restaurant detail sticky action bar', () => {
    test('shows the action bar with call, directions, and website on mobile', async ({ page }) => {
        await gotoAndSettle(page, '/restaurants/' + FIXTURE_SLUG)

        const bar = page.getByTestId('restaurant-action-bar')
        await expect(bar).toBeVisible()
        await expect(bar).toContainText('Directions')
        await expect(bar).toContainText('Call')
        await expect(bar).toContainText('Website')
    })

    test('action bar is hidden on desktop-width viewports', async ({ browser }) => {
        const desktop = await browser.newContext({
            viewport: { width: 1440, height: 900 },
        })
        const page = await desktop.newPage()
        await gotoAndSettle(page, '/restaurants/' + FIXTURE_SLUG)

        await expect(page.getByTestId('restaurant-action-bar')).toBeHidden()
        await desktop.close()
    })
})

test.describe('Bottom tab bar', () => {
    test('shows the five primary destinations on mobile', async ({ page }) => {
        await gotoHome(page)

        const bar = page.getByTestId('bottom-tab-bar')
        await expect(bar).toBeVisible()

        for (const label of ['Search', 'Browse', 'Leaderboard', 'Saved', 'Account']) {
            await expect(bar.getByRole('link', { name: label })).toBeVisible()
        }
    })

    test('marks the current section as the active tab', async ({ page }) => {
        await gotoAndSettle(page, '/search')

        const active = page.getByTestId('bottom-tab-bar').locator('[aria-current="page"]')
        await expect(active).toHaveText('Search')
    })

    test('routes the auth-gated tabs to /login when signed out', async ({ page }) => {
        await gotoHome(page)

        const bar = page.getByTestId('bottom-tab-bar')
        await expect(bar.getByRole('link', { name: 'Saved' })).toHaveAttribute('href', '/login')
        await expect(bar.getByRole('link', { name: 'Account' })).toHaveAttribute('href', '/login')
    })

    test('is hidden on desktop-width viewports', async ({ browser }) => {
        const desktop = await browser.newContext({ viewport: { width: 1440, height: 900 } })
        const page = await desktop.newPage()
        await gotoAndSettle(page, '/')

        await expect(page.getByTestId('bottom-tab-bar')).toBeHidden()
        await desktop.close()
    })
})
