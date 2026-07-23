import type { Restaurant } from '@/types/restaurant';
import { cuisineGradient, FOOD_FALLBACK_GRADIENT } from '@/lib/cuisine';
import { mapsUrl } from '@/lib/restaurant';

export interface RankStyle {
    bg: string;
    text: string;
    ring?: string;
}

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

export function getRankStyle(rank: number): RankStyle {
    if (rank === 1) return { bg: 'from-amber-400 to-yellow-500', text: 'text-white', ring: 'shadow-amber-500/30' };
    if (rank === 2) return { bg: 'from-slate-300 to-slate-400', text: 'text-slate-900', ring: 'shadow-slate-400/30' };
    if (rank === 3) return { bg: 'from-orange-400 to-amber-600', text: 'text-white', ring: 'shadow-orange-500/30' };
    return { bg: 'from-gray-800 to-gray-900', text: 'text-white', ring: 'shadow-gray-900/30' };
}

export function isTopRank(rank: number): boolean {
    return rank >= 1 && rank <= 3;
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
    return Array.from(
        new Set([restaurant.photo_url, ...(restaurant.photos ?? [])].filter(Boolean))
    ).slice(0, 6) as string[];
}

export function getMapCoords(restaurant: Restaurant): { lat: number; lng: number } | null {
    if (restaurant.lat != null && restaurant.lng != null) {
        return { lat: restaurant.lat, lng: restaurant.lng };
    }
    return null;
}
