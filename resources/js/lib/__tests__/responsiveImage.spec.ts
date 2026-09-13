import { describe, it, expect } from 'vitest'
import { commonsSrcset } from '@/lib/responsiveImage'

describe('commonsSrcset', () => {
    it('offers the standard Commons thumbnail widths for a thumb URL', () => {
        const set = commonsSrcset('https://upload.wikimedia.org/wikipedia/commons/thumb/d/de/Moose%27s_Tooth.jpg/960px-Moose%27s_Tooth.jpg')
        expect(set).toBe([
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
