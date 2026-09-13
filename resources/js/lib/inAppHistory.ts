import { reactive, readonly } from 'vue'
import { router } from '@inertiajs/vue3'

/**
 * Whether the page on screen was opened by a link inside the site, and which
 * page that was. A page's own "Back" link can then do what the browser's back
 * button does (return to the results, filters and scroll kept), which matters
 * when the site runs full screen from the home screen: iOS shows no back
 * button there.
 *
 * The first page load and the browser's back/forward don't count as opened by
 * a link: the entry before them may be another site.
 */
interface InAppHistory {
    openedByLink: boolean
    previousUrl: string | null
}

const state = reactive<InAppHistory>({ openedByLink: false, previousUrl: null })

let started = false
let navigations = 0
let fromHistory = false
let currentUrl: string | null = null

export function trackInAppHistory(): void {
    if (started || typeof window === 'undefined') return
    started = true

    // Fires before Inertia's own handler finishes restoring the page.
    window.addEventListener('popstate', () => {
        fromHistory = true
    })

    router.on('navigate', (event) => {
        navigations++
        const url = event.detail.page.url
        state.openedByLink = navigations > 1 && !fromHistory && url !== currentUrl
        state.previousUrl = currentUrl
        currentUrl = url
        fromHistory = false
    })
}

export const inAppHistory = readonly(state)

const RESULTS_PATHS = [/^\/$/, /^\/search(\?|$)/, /^\/restaurants(\?|$)/, /^\/leaderboard(\?|$)/, /^\/favorites(\?|$)/, /^\/cuisine\//]

/** Whether a URL on this site is a list of restaurants (search, home, leaderboard…). */
export function isResultsUrl(url: string | null): boolean {
    return url !== null && RESULTS_PATHS.some((re) => re.test(url))
}

/** Test-only: forget the pages seen so far (the listeners stay). */
export function resetInAppHistory(): void {
    navigations = 0
    fromHistory = false
    currentUrl = null
    state.openedByLink = false
    state.previousUrl = null
}
