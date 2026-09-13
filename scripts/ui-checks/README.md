# UI checks

Browser checks used for the 2026-09 redesign. Each takes a base URL (the local
prod clone at `http://127.0.0.1:8090`, or `https://ipop360.com` after a deploy)
and checks a phone (390 px) and a desktop (1440 px) viewport.

| Script | What it checks |
|---|---|
| `a11y.mjs <base> <path>…` | axe-core (WCAG 2.2 AA + best practice) on each path. Needs axe-core: `npx -y npm@10.9.2 install --prefix /tmp/axe axe-core@4.10.3`, or set `AXE_PATH`. |
| `imgweight.mjs <base> [path]` | Bytes by resource type after scrolling the page, and the 12 heaviest images. |
| `navflow.mjs <base> <outdir>` | Search → a restaurant (in-app, no full page load) → its Back link returns to the results with filters and scroll kept. Also fetches `/manifest.json`. |

Run from the repo root, for example
`CHROMIUM=~/.cache/ms-playwright/chromium_headless_shell-1208/chrome-headless-shell-linux64/chrome-headless-shell node scripts/ui-checks/a11y.mjs http://127.0.0.1:8090 / "/search?city=Austin&state=TX"`.
Scroll before full-page screenshots (the home page reveals sections on scroll),
and measure overflow against `visualViewport.width`: on a phone-emulated page,
`innerWidth` grows to fit content that overflows.
