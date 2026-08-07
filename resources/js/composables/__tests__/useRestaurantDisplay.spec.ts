import { describe, it, expect } from 'vitest';
import type { Restaurant } from '@/types/restaurant';
import {
  getDetailUrl,
  getRankStyle,
  isTopRank,
  getDisplayRating,
  getRestaurantGradient,
  getRestaurantPhotos,
  getMapCoords,
} from '@/composables/useRestaurantDisplay';

function makeRestaurant(overrides: Partial<Restaurant> = {}): Restaurant {
  return {
    id: 42,
    name: 'Test Place',
    slug: 'test-place',
    description: null,
    address: '123 Main St',
    city: 'Austin',
    state: 'TX',
    lat: 30.27,
    lng: -97.74,
    photo_url: null,
    price_range: '$$',
    phone: '512-555-1212',
    website_url: null,
    google_rating: null,
    google_review_count: 0,
    yelp_rating: null,
    yelp_review_count: 0,
    has_award: false,
    popularity_score: 0.5,
    distance: null,
    cuisines: [],
    source: 'bizdata',
    ...overrides,
  };
}

describe('getDetailUrl', () => {
  it('returns persisted restaurant URL when id > 0', () => {
    const r = makeRestaurant({ id: 42, slug: 'best-tacos' });
    expect(getDetailUrl(r)).toBe('/restaurants/best-tacos');
  });

  it('returns preview URL when id <= 0 and slug is present', () => {
    const r = makeRestaurant({ id: 0, slug: 'preview-tacos' });
    expect(getDetailUrl(r)).toBe('/restaurants/preview/preview-tacos');
  });

  it('returns maps URL when id <= 0 and no slug', () => {
    const r = makeRestaurant({ id: 0, slug: '', name: 'Taco Spot', city: 'Dallas' });
    const url = getDetailUrl(r);
    expect(url).toContain('google.com/maps');
    expect(url).toContain('Taco%20Spot');
    expect(url).toContain('Dallas');
  });
});

describe('getRankStyle', () => {
  it('returns gold style for rank 1', () => {
    expect(getRankStyle(1).bg).toContain('amber');
    expect(getRankStyle(1).text).toBe('text-white');
  });

  it('returns silver style for rank 2', () => {
    expect(getRankStyle(2).bg).toContain('slate');
    expect(getRankStyle(2).text).toBe('text-slate-900');
  });

  it('returns bronze style for rank 3', () => {
    expect(getRankStyle(3).bg).toContain('orange');
    expect(getRankStyle(3).text).toBe('text-white');
  });

  it('returns default style for rank 4+', () => {
    expect(getRankStyle(4).bg).toContain('gray');
    expect(getRankStyle(4).text).toBe('text-white');
  });

  it('returns default style for rank 0', () => {
    expect(getRankStyle(0).bg).toContain('gray');
    expect(getRankStyle(0).text).toBe('text-white');
  });
});

describe('isTopRank', () => {
  it('returns true for rank 1', () => expect(isTopRank(1)).toBe(true));
  it('returns true for rank 2', () => expect(isTopRank(2)).toBe(true));
  it('returns true for rank 3', () => expect(isTopRank(3)).toBe(true));
  it('returns false for rank 4', () => expect(isTopRank(4)).toBe(false));
  it('returns false for rank 0', () => expect(isTopRank(0)).toBe(false));
});

describe('getDisplayRating', () => {
  it('prefers yelp rating when available', () => {
    const r = makeRestaurant({
      yelp_rating: 4.5,
      yelp_review_count: 200,
      google_rating: 4.2,
      google_review_count: 100,
    });
    const result = getDisplayRating(r);
    expect(result).toEqual({ rating: 4.5, count: 200, source: 'Yelp' });
  });

  it('falls back to google rating when yelp is null', () => {
    const r = makeRestaurant({
      yelp_rating: null,
      google_rating: 4.2,
      google_review_count: 100,
    });
    const result = getDisplayRating(r);
    expect(result).toEqual({ rating: 4.2, count: 100, source: 'Google' });
  });

  it('returns null when neither rating is available', () => {
    const r = makeRestaurant({ yelp_rating: null, google_rating: null });
    expect(getDisplayRating(r)).toBeNull();
  });
});

describe('getRestaurantGradient', () => {
  it('returns cuisine-specific gradient when cuisines exist', () => {
    const r = makeRestaurant({
      cuisines: [{ id: 1, name: 'Italian', slug: 'italian' }],
    });
    const result = getRestaurantGradient(r);
    expect(result).toContain('e63946');
  });

  it('returns fallback gradient when cuisines array is empty', () => {
    const r = makeRestaurant({ cuisines: [] });
    const result = getRestaurantGradient(r);
    expect(result).toContain('1d3557');
  });
});

describe('getRestaurantPhotos', () => {
  it('combines photo_url and photos array, deduplicating', () => {
    const r = makeRestaurant({
      photo_url: 'https://img.example.com/hero.jpg',
      photos: ['https://img.example.com/hero.jpg', 'https://img.example.com/2.jpg'],
    });
    const result = getRestaurantPhotos(r);
    expect(result).toEqual([
      'https://img.example.com/hero.jpg',
      'https://img.example.com/2.jpg',
    ]);
  });

  it('caps at 6 photos', () => {
    const photos = Array.from({ length: 10 }, (_, i) => `https://img.example.com/${i}.jpg`);
    const r = makeRestaurant({ photo_url: null, photos });
    const result = getRestaurantPhotos(r);
    expect(result).toHaveLength(6);
  });

  it('returns photo_url only when photos is undefined', () => {
    const r = makeRestaurant({ photo_url: 'https://img.example.com/solo.jpg', photos: undefined });
    const result = getRestaurantPhotos(r);
    expect(result).toEqual(['https://img.example.com/solo.jpg']);
  });

  it('returns empty array when both are null/undefined', () => {
    const r = makeRestaurant({ photo_url: null, photos: undefined });
    expect(getRestaurantPhotos(r)).toEqual([]);
  });
});

describe('getMapCoords', () => {
  it('returns lat/lng when both are present', () => {
    const r = makeRestaurant({ lat: 30.27, lng: -97.74 });
    expect(getMapCoords(r)).toEqual({ lat: 30.27, lng: -97.74 });
  });

  it('returns null when lat is null', () => {
    const r = makeRestaurant({ lat: null, lng: -97.74 });
    expect(getMapCoords(r)).toBeNull();
  });

  it('returns null when lng is null', () => {
    const r = makeRestaurant({ lat: 30.27, lng: null });
    expect(getMapCoords(r)).toBeNull();
  });

  it('returns null when both are null', () => {
    const r = makeRestaurant({ lat: null, lng: null });
    expect(getMapCoords(r)).toBeNull();
  });
});
