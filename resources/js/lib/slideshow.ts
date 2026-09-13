export interface Slide {
    /** Unsplash photo id (images.unsplash.com/<id>) */
    id: string
    attribution: string
}

export const slides: Slide[] = [
    { id: 'photo-1555396273-367ea4eb4db5', attribution: 'Photo by Chander R on Unsplash' },
    { id: 'photo-1414235077428-338989a2e8c0', attribution: 'Photo by Alisa Anton on Unsplash' },
    { id: 'photo-1504674900247-0877df9cc836', attribution: 'Photo by Lily Banse on Unsplash' },
    { id: 'photo-1567620905732-2d1ec7ab7445', attribution: 'Photo by Kelly Sikkema on Unsplash' },
    { id: 'photo-1466978913421-dad2ebd01d17', attribution: 'Photo by Farhad Ibrahimzade on Unsplash' },
]

export interface SlideSources {
    /** Portrait crops for phones (the hero is taller than it is wide there). */
    phone: string
    /** Landscape crops for everything wider. */
    wide: string
    /** Plain src for browsers that ignore srcset. */
    fallback: string
}

/**
 * Sized, cropped sources for one slide. Unsplash's `auto=format` serves AVIF
 * or WebP to browsers that accept them. The old slides were one 1600px JPEG
 * each, about 2.1 MB for all five, downloaded on phones too.
 */
export function slideSources(slide: Slide): SlideSources {
    const base = `https://images.unsplash.com/${slide.id}?auto=format&fit=crop&q=60`

    return {
        phone: `${base}&w=600&h=900 600w, ${base}&w=900&h=1350 900w`,
        wide: `${base}&w=960&h=600 960w, ${base}&w=1600&h=900 1600w, ${base}&w=2200&h=1100 2200w`,
        fallback: `${base}&w=1600&h=900`,
    }
}
