import { test, expect, type Page } from '@playwright/test'

// Horizontal-reflow regression harness. Asserts that every phone-visible route
// renders without horizontal overflow at the WCAG reflow floor (320px) and the
// iPhone viewport the audit was triggered by (393px). On failure it names the
// offending elements, so a regression points at its own culprit.
//
// The input <16px guard (iOS auto-zoom root cause) lands with the PR that fixes
// those controls: Chromium cannot reproduce iOS Safari's focus zoom, so the
// effective guard is a computed font-size assertion and it is red until the
// fixes ship.
//
// Fixture data (restaurant + user) comes from database/seeders/E2ESeeder.php;
// run it before this spec: `php artisan db:seed --class=E2ESeeder`.

const BASE = 'http://localhost:8090'

// Keep in sync with E2ESeeder::RESTAURANT_SLUG.
const FIXTURE_SLUG = 'e2e-fixture-kitchen'

const ROUTES: Array<{ name: string; path: string }> = [
    { name: 'home', path: '/' },
    { name: 'search', path: '/search' },
    { name: 'restaurant detail', path: `/restaurants/${FIXTURE_SLUG}` },
    { name: 'leaderboard', path: '/leaderboard' },
    { name: 'compare', path: '/compare' },
    { name: 'blog', path: '/blog' },
]

// 320px is the WCAG 2.2 reflow floor (1.4.10); 393px is the iPhone 16 Pro CSS
// viewport the audit was triggered by.
const WIDTHS = [320, 393]

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

for (const width of WIDTHS) {
    test.describe(`no horizontal overflow @ ${width}px`, () => {
        test.use({ viewport: { width, height: 852 } })

        for (const route of ROUTES) {
            test(`${route.name} (${route.path})`, async ({ page }) => {
                await page.goto(BASE + route.path)
                await page.waitForLoadState('networkidle')

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
