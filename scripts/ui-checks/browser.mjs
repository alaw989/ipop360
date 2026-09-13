// Headless Chromium for the UI checks. Playwright's bundled browser when it's
// installed (`npx playwright install chromium`); otherwise set CHROMIUM to an
// executable (on the Deck: ~/.cache/ms-playwright/chromium_headless_shell-1208/
// chrome-headless-shell-linux64/chrome-headless-shell).
export function launchOptions() {
    return process.env.CHROMIUM ? { executablePath: process.env.CHROMIUM } : {};
}
