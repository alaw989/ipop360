/**
 * A srcset for restaurant photos on hosts that resize on request, so a 96px
 * card thumbnail doesn't download a 4000px original. Other hosts get null:
 * their images are used as they come.
 *
 * - Wikimedia Commons: many photos point at the full-size original (a 3 MB
 *   file); Commons serves standard thumbnail widths.
 * - Google (lh3.googleusercontent.com): the size is the part after the last
 *   "=" ("=w1000-h1000-c-n"); "=w400-h400-rw" is a WebP that fits in 400px
 *   (the height cap keeps a tall photo from coming back 400 × 2000).
 */
// Commons' standard thumbnail steps (other widths are rendered on demand and
// may be refused); Google renders any size.
const COMMONS_WIDTHS = [250, 500, 960, 1280, 1920] as const;
const GOOGLE_WIDTHS = [200, 400, 800, 1200, 1600] as const;

export function commonsSrcset(url: string | null | undefined): string | null {
    if (!url) return null;

    // upload.wikimedia.org/wikipedia/commons/thumb/d/de/File.jpg/960px-File.jpg
    const thumb = /^(https:\/\/upload\.wikimedia\.org\/wikipedia\/commons\/thumb\/[0-9a-f]\/[0-9a-f]{2}\/([^/]+))\/\d+px-\2$/i.exec(url);
    if (thumb) {
        return COMMONS_WIDTHS.map((w) => `${thumb[1]}/${w}px-${thumb[2]} ${w}w`).join(', ');
    }

    // commons.wikimedia.org/wiki/Special:FilePath/File.jpg (the original)
    if (/^https?:\/\/commons\.wikimedia\.org\/wiki\/Special:FilePath\/[^?]+$/i.test(url)) {
        const base = url.replace(/^http:/, 'https:');
        return COMMONS_WIDTHS.map((w) => `${base}?width=${w} ${w}w`).join(', ');
    }

    return null;
}

export function googleSrcset(url: string | null | undefined): string | null {
    if (!url) return null;
    const match = /^(https:\/\/lh\d\.googleusercontent\.com\/[^=?#]+)(?:=[\w-]*)?$/.exec(url);
    if (!match) return null;
    return GOOGLE_WIDTHS.map((w) => `${match[1]}=w${w}-h${w}-rw ${w}w`).join(', ');
}

/** The srcset for any restaurant photo, or null when its host can't resize. */
export function photoSrcset(url: string | null | undefined): string | null {
    return commonsSrcset(url) ?? googleSrcset(url);
}
