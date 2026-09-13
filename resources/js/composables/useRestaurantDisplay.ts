import type { Restaurant } from '@/types/restaurant';
import { cuisineGradient, FOOD_FALLBACK_GRADIENT } from '@/lib/cuisine';
import { mapsUrl } from '@/lib/restaurant';

export interface DisplayRating {
    rating: number;
    count: number;
    source: 'Yelp' | 'Google';
}

export function getDetailUrl(restaurant: Restaurant): string {
    if (restaurant.id > 0) {
        return `/restaurants/${restaurant.slug}`;
    }
    if (restaurant.slug) {
        return `/restaurants/preview/${restaurant.slug}`;
    }
    return mapsUrl(restaurant.name, restaurant.city);
}

export function getDisplayRating(restaurant: Restaurant): DisplayRating | null {
    if (restaurant.yelp_rating) return { rating: restaurant.yelp_rating, count: restaurant.yelp_review_count, source: 'Yelp' };
    if (restaurant.google_rating) return { rating: restaurant.google_rating, count: restaurant.google_review_count, source: 'Google' };
    return null;
}

export function getRestaurantGradient(restaurant: Restaurant): string {
    const primaryCuisine = restaurant.cuisines[0]?.slug;
    return primaryCuisine ? cuisineGradient(primaryCuisine) : FOOD_FALLBACK_GRADIENT;
}

export function getRestaurantPhotos(restaurant: Restaurant): string[] {
    // The card-sized thumbnail leads the gallery when present; the full-size
    // original stays as a later slide (and for lightweight hosts, as the only
    // entry — photo_thumb_url is omitted when it isn't present).
    return Array.from(
        new Set([restaurant.photo_thumb_url ?? restaurant.photo_url, ...(restaurant.photos ?? [])].filter(Boolean))
    ).slice(0, 6) as string[];
}

export function getMapCoords(restaurant: Restaurant): { lat: number; lng: number } | null {
    if (restaurant.lat != null && restaurant.lng != null) {
        return { lat: restaurant.lat, lng: restaurant.lng };
    }
    return null;
}
