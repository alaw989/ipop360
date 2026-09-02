import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

// Drift-guard for the global reduced-motion block (spec-098 item 2). The
// transitions.css block only loads with Welcome.vue, so every other page's
// .animate-spin/.animate-pulse and smooth-scroll must be neutralized globally
// in app.css. If the block is ever removed, this goes red.
describe('app.css global reduced-motion coverage', () => {
    const css = readFileSync(resolve(import.meta.dirname, '../../../css/app.css'), 'utf8');

    it('defines a prefers-reduced-motion reduce block', () => {
        expect(css).toMatch(/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)/);
    });

    it('neutralizes Tailwind .animate-spin and .animate-pulse', () => {
        const reduceBlock = css.split(/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)/)[1];
        expect(reduceBlock).toBeDefined();
        expect(reduceBlock).toMatch(/\.animate-spin/);
        expect(reduceBlock).toMatch(/\.animate-pulse/);
        expect(reduceBlock).toMatch(/animation:\s*none\s*!important/);
    });

    it('forces scroll-behavior auto under reduced motion', () => {
        const reduceBlock = css.split(/@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)/)[1];
        expect(reduceBlock).toMatch(/scroll-behavior:\s*auto\s*!important/);
    });
});
