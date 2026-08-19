import { test, expect, type Page } from '@playwright/test'

// Mobile UX E2E pass (goal #11). Runs against the local dev stack on :8090 with
// an iPhone-class touch viewport. Covers the mobile-only affordances added by
// the redesign: the nav drawer, the search filter sheet + map/list toggle, and
// the sticky restaurant action bar. Requires real seed data for the detail page
// (a restaurant with phone + website + coordinates in the local DB).

const BASE = 'http://localhost:8090'

async function gotoHome(page: Page) {
    await page.goto(BASE + '/')
    await page.waitForLoadState('networkidle')
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
        await page.goto(BASE + '/search')
        await page.waitForLoadState('networkidle')
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
        await page.goto(BASE + '/search')
        await page.waitForLoadState('networkidle')

        await expect(page.getByTestId('mobile-filter-toggle')).toBeHidden()
        await expect(page.getByTestId('mobile-map-toggle')).toBeHidden()
        await desktop.close()
    })
})

test.describe('Restaurant detail sticky action bar', () => {
    test('shows the action bar with call, directions, and website on mobile', async ({ page }) => {
        await page.goto(BASE + '/restaurants/savinas-mexican-kitchen-downtown-denver-6cqTTV')
        await page.waitForLoadState('networkidle')

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
        await page.goto(BASE + '/restaurants/savinas-mexican-kitchen-downtown-denver-6cqTTV')
        await page.waitForLoadState('networkidle')

        await expect(page.getByTestId('restaurant-action-bar')).toBeHidden()
        await desktop.close()
    })
})
