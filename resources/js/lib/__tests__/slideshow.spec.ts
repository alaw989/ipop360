import { describe, it, expect } from 'vitest';
import { slides, slideSources } from '@/lib/slideshow';

describe('slideshow config', () => {
    it('exports exactly 5 slides', () => {
        expect(slides).toHaveLength(5);
    });

    it('each slide has an Unsplash photo id and attribution', () => {
        for (const slide of slides) {
            expect(slide.id).toMatch(/^photo-/);
            expect(slide.attribution).toMatch(/^Photo by .+ on Unsplash$/);
        }
    });

    it('serves phones portrait crops no wider than 900px', () => {
        const { phone } = slideSources(slides[0]!);
        expect(phone).toContain('w=600&h=900 600w');
        expect(phone).toContain('w=900&h=1350 900w');
        expect(phone).not.toContain('w=1600');
    });

    it('serves wider screens landscape crops up to 2200px', () => {
        const { wide, fallback } = slideSources(slides[0]!);
        expect(wide).toContain('960w');
        expect(wide).toContain('1600w');
        expect(wide).toContain('2200w');
        expect(fallback).toContain('w=1600&h=900');
    });

    it('lets Unsplash pick a modern format and crop to the requested size', () => {
        for (const slide of slides) {
            const { phone, wide, fallback } = slideSources(slide);
            for (const url of [phone, wide, fallback]) {
                expect(url).toContain(`images.unsplash.com/${slide.id}`);
                expect(url).toContain('auto=format');
                expect(url).toContain('fit=crop');
            }
        }
    });
});
