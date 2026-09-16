import { test, expect, type Page } from '@playwright/test'

// Mobile reflow + input-zoom guard. Asserts that every phone-visible route
// renders without horizontal overflow at the WCAG reflow floor (320px) and the
// iPhone viewport the audit was triggered by (393px); on failure it names the
// offending elements.
//
// It also asserts every phone-visible form control is >=16px. iOS Safari
// auto-zooms (and does not zoom back out) when a focused input/select is under
// 16px, which is the root cause of the page going wider than the viewport.
// Chromium cannot reproduce that zoom, so a computed font-size assertion is the
// effective guard.
//
// Fixture data (restaurant + user) comes from database/seeders/E2ESeeder.php;
// run it before this spec: `php artisan db:seed --class=E2ESeeder`.

const BASE = 'http://localhost:8090'

// Keep in sync with E2ESeeder::RESTAURANT_SLUG.
const FIXTURE_SLUG = 'e2e-fixture-kitchen'

const ROUTES: Array<{ name: string; path: string }> = [
    { name: 'home', path: '/' },
    { name: 'search', path: '/search' },
    { name: 'browse', path: '/restaurants' },
    { name: 'restaurant detail', path: `/restaurants/${FIXTURE_SLUG}` },
    { name: 'leaderboard', path: '/leaderboard' },
    { name: 'compare', path: '/compare' },
    { name: 'blog', path: '/blog' },
]

// 320px is the WCAG 2.2 reflow floor (1.4.10); 393px is the iPhone 16 Pro CSS
// viewport the audit was triggered by.
const WIDTHS = [320, 393]

// `networkidle` is discouraged and flaky here: real dev data loads third-party
// restaurant photos and the PHP dev server can queue under parallel workers, so
// it may never settle. Waiting for the app shell to render is a stable signal
// and is enough for layout to be measurable.
async function gotoAndSettle(page: Page, path: string) {
    await page.goto(BASE + path)
    await page.waitForLoadState('domcontentloaded')
    await page.locator('main').first().waitFor({ state: 'visible' })
    await page.waitForTimeout(200)
}

interface OverflowReport {
    scrollWidth: number
    clientWidth: number
    offenders: string[]
}

async function measureOverflow(page: Page): Promise<OverflowReport> {
    return page.evaluate(() => {
        const doc = document.documentElement
        const limit = doc.clientWidth
        const offenders: string[] = []

        if (doc.scrollWidth > limit + 1) {
            document.querySelectorAll<HTMLElement>('body *').forEach((el) => {
                const rect = el.getBoundingClientRect()
                if (rect.width === 0 || rect.right <= limit + 1) return
                const cls = (el.getAttribute('class') ?? '').slice(0, 120)
                offenders.push(
                    `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}` +
                        `[${cls}] right=${Math.round(rect.right)} (+${Math.round(rect.right - limit)}px)`,
                )
            })
        }

        return { scrollWidth: doc.scrollWidth, clientWidth: limit, offenders: offenders.slice(0, 20) }
    })
}

// Every visible input/select/textarea whose computed font-size is below 16px —
// the iOS auto-zoom trigger. Returns "<tag><id><name><placeholder> <px>".
async function scanSubSixteenControls(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const out: string[] = []
        document.querySelectorAll<HTMLElement>('input, select, textarea').forEach((el) => {
            const rect = el.getBoundingClientRect()
            const style = getComputedStyle(el)
            const visible =
                el.offsetParent !== null &&
                rect.width > 0 &&
                rect.height > 0 &&
                style.visibility !== 'hidden'
            if (!visible) return

            const size = parseFloat(style.fontSize)
            if (size < 16) {
                const id = el.id ? '#' + el.id : ''
                const name = el.getAttribute('name') ? '[name=' + el.getAttribute('name') + ']' : ''
                const placeholder = el.getAttribute('placeholder')
                    ? '[placeholder="' + el.getAttribute('placeholder') + '"]'
                    : ''
                out.push(`${el.tagName.toLowerCase()}${id}${name}${placeholder} ${size}px`)
            }
        })
        return out
    })
}

for (const width of WIDTHS) {
    test.describe(`no horizontal overflow @ ${width}px`, () => {
        test.use({ viewport: { width, height: 852 } })

        for (const route of ROUTES) {
            test(`${route.name} (${route.path})`, async ({ page }) => {
                await gotoAndSettle(page, route.path)

                const report = await measureOverflow(page)

                expect(
                    report.scrollWidth,
                    `horizontal overflow on ${route.path} (${width}px).\n` +
                        `scrollWidth=${report.scrollWidth} clientWidth=${report.clientWidth}\n` +
                        `Offending elements:\n${report.offenders.join('\n') || '(none reported)'}`,
                ).toBeLessThanOrEqual(report.clientWidth + 1)
            })
        }
    })
}

test.describe('phone-visible controls are >=16px (no iOS focus zoom)', () => {
    test.use({ viewport: { width: 393, height: 852 } })

    for (const route of ROUTES) {
        test(`${route.name}: no sub-16px visible control`, async ({ page }) => {
            await gotoAndSettle(page, route.path)

            const offenders = await scanSubSixteenControls(page)

            expect(
                offenders,
                `Sub-16px controls on ${route.path} trigger iOS auto-zoom:\n${offenders.join('\n')}`,
            ).toEqual([])
        })
    }

    test('city picker sheet input is >=16px', async ({ page }) => {
        await gotoAndSettle(page, '/')

        await page.getByTestId('location-trigger').first().click()
        await expect(page.getByPlaceholder('Type your city...').first()).toBeVisible()

        const offenders = await scanSubSixteenControls(page)
        expect(offenders, `Sub-16px controls in the city sheet:\n${offenders.join('\n')}`).toEqual([])
    })

    test('cuisine picker sheet input is >=16px', async ({ page }) => {
        await gotoAndSettle(page, '/')

        await page.getByTestId('cuisine-trigger').first().click()
        await expect(page.getByPlaceholder('Search cuisines...').first()).toBeVisible()

        const offenders = await scanSubSixteenControls(page)
        expect(offenders, `Sub-16px controls in the cuisine sheet:\n${offenders.join('\n')}`).toEqual([])
    })
})
