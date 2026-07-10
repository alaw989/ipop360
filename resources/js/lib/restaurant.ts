/**
 * Restaurant utility functions.
 * Shared helpers for phone, website, and maps interactions.
 */

/**
 * Track a user engagement event (fire-and-forget POST).
 */
function trackEngagement(restaurantId: number, action: 'website' | 'directions' | 'call'): void {
    fetch('/api/engage', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ restaurant_id: restaurantId, action }),
    }).catch(() => { /* fire-and-forget */ });
}

/**
 * Initiate a phone call to the given number.
 * @param phone - The phone number to call (e.g., '512-555-1234')
 * @param restaurantId - Optional restaurant ID for engagement tracking
 */
export function callPhone(phone: string, restaurantId?: number): void {
    if (restaurantId) {
        trackEngagement(restaurantId, 'call');
    }
    window.location.href = `tel:${phone}`;
}

/**
 * Open a website in a new tab.
 * Prepends 'https://' if the URL doesn't start with a protocol.
 * @param url - The website URL to open
 * @param restaurantId - Optional restaurant ID for engagement tracking
 */
export function openWebsite(url: string, restaurantId?: number): void {
    if (restaurantId) {
        trackEngagement(restaurantId, 'website');
    }
    if (!url.startsWith('http')) {
        url = `https://${url}`;
    }
    window.open(url, '_blank');
}

/**
 * Track a directions click for a restaurant.
 */
export function trackDirections(restaurantId: number): void {
    trackEngagement(restaurantId, 'directions');
}

/**
 * Generate a Google Maps search URL for a restaurant.
 * @param name - Restaurant name
 * @param city - Restaurant city (optional, for better search results)
 * @returns Google Maps search URL
 */
export function mapsUrl(name: string, city: string | null = null): string {
    const query = city ? `${name}, ${city}` : name;
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`;
}

/**
 * Generate a Google Maps directions URL to a restaurant.
 * @param lat - Restaurant latitude
 * @param lng - Restaurant longitude
 * @returns Google Maps directions URL
 */
export function directionsUrl(lat: number, lng: number): string {
    return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
}
