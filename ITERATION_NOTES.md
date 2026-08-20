# Iteration Notes

## Goal
Behavior-preserving refactor of the just-shipped hero-stats, AI-fallback, and sheet-a11y PRs. 1) useCountUp + HeroBanner: collapse the 3x useCountUp + statsItems duplicate getValue/target closures; simplify animateTo/run (overlapping settled / reduced-motion / no-op paths); parameterize useCountUp.spec.ts. 2) AiEnrichmentService::callProviders: consolidate the 3 near-identical catch/continue blocks into one retryable -> next-provider path; extract the literal system prompt in tryProvider into a class constant. 3) SheetTitle/SheetDescription + specs: keep the two thin components separate (shadcn convention); DRY the duplicated spec bodies via a shared factory; drop unneeded props.as!/asChild! assertions if reka-ui provides defaults.

## State
All 3 parts verified green this iteration: vitest 1111 passed (74 files, includes useCountUp/sheet/hero specs), AiEnrichment backend tests 26 passed. Goal complete — nothing left to improve.

## Log
- SheetTitle/SheetDescription: removed `props.as!`/`asChild!` non-null assertions; specs share mountInSheet factory (sheet.spec.helpers.ts).
- AiEnrichmentService: consolidated 3 catch/continue blocks into one retryable -> next-provider path; extracted SYSTEM_PROMPT constant.
- useCountUp.spec.ts: parameterized via it.each + mockMotion/mockRaf helpers; dropped redundant rafSpy.mockRestore() (afterEach restoreAllMocks covers it).
- useCountUp: simplified animateTo — settledTarget set once at entry; single completion branch; rafId nulled on finish.
- HeroBanner: collapsed 3x useCountUp + statsItems duplicate closures into one statDefs array (delay i*80), items expose value/target directly.

