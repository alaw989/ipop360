import { describe, it, expect } from 'vitest'
import { commonsSrcset, googleSrcset, photoSrcset } from '@/lib/responsiveImage'

describe('commonsSrcset', () => {
    it('offers the standard Commons thumbnail widths for a thumb URL', () => {
        const set = commonsSrcset('https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose%27s_Tooth.jpg/960px-Moose%27s_Tooth.jpg')
        expect(set).toBe([
            'https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose%27s_Tooth.jpg/250px-Moose%27s_Tooth.jpg 250w',
            'https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose%27s_Tooth.jpg/500px-Moose%27s_Tooth.jpg 500w',
            'https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose%27s_Tooth.jpg/960px-Moose%27s_Tooth.jpg 960w',
            'https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose%27s_Tooth.jpg/1280px-Moose%27s_Tooth.jpg 1280w',
            'https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose%27s_Tooth.jpg/1920px-Moose%27s_Tooth.jpg 1920w',
        ].join(', '))
    })

    it('asks Special:FilePath originals for a width, over https', () => {
        const set = commonsSrcset('http://commons.wikimedia.org/wiki/Special:FilePath/Moose.jpg')
        expect(set).toContain('https://commons.wikimedia.org/wiki/Special:FilePath/Moose.jpg?width=960 960w')
    })

    it('leaves other hosts alone', () => {
        expect(commonsSrcset('https://example.com/photo.jpg')).toBeNull()
        expect(commonsSrcset(null)).toBeNull()
    })
})

describe('googleSrcset', () => {
    const base = 'https://lh3.googleusercontent.com/gps-cs-s/AHRPTWkFz7VSIgQ-xXL4No9qUB1Dz'

    it('swaps the stored size for WebP sizes that fit the width', () => {
        expect(googleSrcset(`${base}=w1000-h1000-c-n`)).toBe([
            `${base}=w200-h200-rw 200w`,
            `${base}=w400-h400-rw 400w`,
            `${base}=w800-h800-rw 800w`,
            `${base}=w1200-h1200-rw 1200w`,
            `${base}=w1600-h1600-rw 1600w`,
        ].join(', '))
    })

    it('adds widths to a URL stored without a size', () => {
        expect(googleSrcset(base)).toContain(`${base}=w400-h400-rw 400w`)
    })

    it('leaves other hosts alone', () => {
        expect(googleSrcset('https://static.wixstatic.com/media/photo.jpg')).toBeNull()
        expect(googleSrcset('https://lh3.googleusercontent.com.evil.example/x=w400')).toBeNull()
    })
})

describe('photoSrcset', () => {
    it('handles either host, and null for the rest', () => {
        expect(photoSrcset('https://commons.wikimedia.org/wiki/Special:FilePath/Moose.jpg')).toContain('?width=500 500w')
        expect(photoSrcset('https://lh5.googleusercontent.com/p/AF1Qip=s0')).toContain('=w800-h800-rw 800w')
        expect(photoSrcset('https://example.com/photo.jpg')).toBeNull()
        expect(photoSrcset(undefined)).toBeNull()
    })
})
