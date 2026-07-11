import { describe, it, expect } from 'vitest';
import { slides } from '@/lib/slideshow';

describe('slideshow config', () => {
    it('exports exactly 5 slides', () => {
        expect(slides).toHaveLength(5);
    });

    it('each slide has an image URL and attribution', () => {
        for (const slide of slides) {
            expect(slide.image).toBeTruthy();
            expect(slide.image).toContain('unsplash.com');
            expect(slide.attribution).toBeTruthy();
            expect(slide.attribution).toMatch(/^Photo by .+ on Unsplash$/);
        }
    });

    it('all image URLs use w=1600 and q=80 params', () => {
        for (const slide of slides) {
            expect(slide.image).toContain('w=1600');
            expect(slide.image).toContain('q=80');
        }
    });
});
