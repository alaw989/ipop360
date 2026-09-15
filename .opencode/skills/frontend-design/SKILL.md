---
name: frontend-design
description: "Use when building, restyling, or reviewing frontend UI in this repo: Vue pages/components, Tailwind styling, shadcn-vue composition, responsive/mobile layout, dark mode, accessibility, and visual polish. Triggers on 'design', 'style', 'redesign', 'make it look', 'responsive', 'mobile layout', 'Tailwind', 'shadcn', 'component', 'spacing', 'polish', or editing resources/js/**/*.vue and resources/css/*.css."
---

# Frontend Design (ipop360)

Deliver UI that looks intentional, matches the existing visual language, and
passes the repo's build + test gates. This is a **test-driven** repo — write or
update the vitest spec before (or with) the component change.

## Stack (do not substitute)

- Inertia 2 + Vue 3, always `<script setup lang="ts">`.
- Tailwind CSS **v4** (CSS-first config, no `tailwind.config.js` tokens).
- **shadcn-vue** (style `reka-nova`) primitives in `resources/js/components/ui/*`.
  Config: `components.json`. Aliases: `@/components`, `@/components/ui`,
  `@/lib/utils`, `@/lib`, `@/composables`.
- Icons: **`@lucide/vue`** (NOT `lucide-vue-next`).
- Fonts: Poppins via `@fontsource/poppins`.
- Maps: `leaflet` + `@types/leaflet`.

## Where things live

| Thing | Path | Convention |
|---|---|---|
| shadcn primitives | `resources/js/components/ui/<name>/` | generated; do not hand-edit |
| App components | `resources/js/Components/*.vue` | PascalCase, grouped by feature |
| Pages | `resources/js/Pages/**` | Inertia pages |
| Layouts | `resources/js/Layouts/` | |
| Composables | `resources/js/composables/use*.ts` | `useX` naming |
| Pure helpers | `resources/js/lib/*.ts` | |
| Tests | beside source in `__tests__/*.spec.ts` | vitest |
| Design tokens | `resources/css/app.css` | see below |

## Design tokens (Tailwind v4, in `resources/css/app.css`)

Tokens are OKLCH CSS variables exposed as `@theme` colors. **Always use the
semantic utility, never raw hex or arbitrary gray values.**

- Surfaces: `bg-background`, `bg-card`, `bg-popover`, `bg-muted`, `bg-accent`
- Text: `text-foreground`, `text-card-foreground`, `text-muted-foreground`
- Brand/actions: `bg-primary` / `text-primary-foreground`; destructive:
  `text-destructive`, `bg-destructive-solid`
- Lines/focus: `border-border`, `bg-input`, `ring-ring`
- Radius: `rounded-*` derived from `--radius`.
- The `.dark` class flips all tokens — never hardcode light/dark colors; use
  tokens plus `dark:` only for non-token accents (e.g. the emerald price text,
  red hearts).

Spacing/sizing pattern already in use: cards `rounded-2xl`, icon pills
`rounded-full`, tap targets `min-h-[44px]`, text scale `text-xs`/`text-sm`/
`text-base`, `tabular-nums` for scores/ranks.

## House style (match existing components)

Look at `resources/js/Components/RestaurantCard.vue` and `ScoreChip.vue` first —
they are the reference for spacing, token use, and interactivity. Conventions:

- Mobile-first. Verify at iPhone-12 width; the E2E project is `iPhone 12`.
- Cards: `group relative overflow-hidden rounded-2xl ... hover:-translate-y-1
  hover:border-primary/30 hover:shadow-xl transition`.
- Interactive elements need `aria-label`/`title` and `focus` states.
- Icon-only buttons are `h-* w-*` circles with a ring; decorative icons get
  `h-3.5 w-3.5`-ish sizing, never raw large.
- Respect `prefers-reduced-motion` — global CSS already disables spin/pulse;
  don't add new always-on motion.
- Prefer `class-variance-authority` + `@/lib/utils` `cn()` for variant APIs.
- Reuse shadcn `Button`, `Badge`, `Card`, `Input`, `Sheet`, `Popover`,
  `Command`, `Skeleton` before writing bespoke primitives.

## shadcn-vue usage

Add primitives with the CLI (matches `components.json`):

```bash
npx shadcn-vue@latest add <component>
```

Generated files go under `resources/js/components/ui/<name>/` and are imported
as `import { Button } from '@/components/ui/button'`.

## Test-driven workflow

1. Read the closest existing component + its spec.
2. Write/update `resources/js/Components/__tests__/<Name>.spec.ts` FIRST.
3. Implement to green.
4. Run gates.

Spec conventions: explicit vitest imports, no globals.

```ts
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MyThing from '@/Components/MyThing.vue'
```

Stub lucide icons when they slow tests (see `ScoreChip.spec.ts`:
`const stubs = { Star: true, ... }`).

## Verification gates (must pass before done)

```bash
npx vitest run resources/js/**/<changed>.spec.ts --reporter=dot
npm run test          # full frontend suite
npm run build         # vue-tsc + vite build + ssr — required, no type drift
```

Optional visual check with the Playwright MCP against the local stack
(`php artisan serve` on :8090, or `composer dev`): open the page, set the
viewport to iPhone 12, screenshot, and inspect console/DOM before calling it
done. Also consider `npm run test:e2e` for mobile flows.

## Anti-patterns

- Raw colors (`bg-[#fff]`, `text-gray-500`) instead of tokens.
- Hardcoding both light and dark colors instead of using semantic tokens.
- Editing files under `resources/js/components/ui/` by hand.
- Importing `lucide-vue-next` (wrong package for this repo).
- Adding a component with no spec — the frontend suite + coverage thresholds
  (`statements 70`, `branches 65`, `functions 60`, `lines 70`) will flag it.
- Changing tests to fit a broken component; fix the component.
