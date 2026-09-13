/**
 * A srcset for photos hosted by Wikimedia Commons, which serves resized copies
 * on request. Many restaurant photos point at the full-size original (a
 * 4000px, 3 MB file for a 400px card). Other hosts get null: their images are
 * used as they come.
 */
const COMMONS_WIDTHS = [500, 960, 1280, 1920] as const;

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
