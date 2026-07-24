type Action = 'website' | 'directions' | 'call' | 'pageview' | 'social_link_click' | 'menu';

function trackEngagement(restaurantId: number, action: Action): void {
    const payload = JSON.stringify({ restaurant_id: restaurantId, action });

    fetch('/api/engage', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: payload,
        keepalive: true,
    }).catch(() => {});
}

export function trackPageview(restaurantId: number): void {
    trackEngagement(restaurantId, 'pageview');
}

export function trackSocialLinkClick(restaurantId: number): void {
    trackEngagement(restaurantId, 'social_link_click');
}

export function callPhone(phone: string, restaurantId?: number): void {
    if (restaurantId) {
        trackEngagement(restaurantId, 'call');
    }
    window.location.href = `tel:${phone}`;
}

export function openWebsite(url: string, restaurantId?: number): void {
    if (restaurantId) {
        trackEngagement(restaurantId, 'website');
    }
    if (!url.startsWith('http')) {
        url = `https://${url}`;
    }
    window.open(url, '_blank');
}

export function trackDirections(restaurantId: number): void {
    trackEngagement(restaurantId, 'directions');
}

export function trackMenuClick(restaurantId: number): void {
    trackEngagement(restaurantId, 'menu');
}

export function mapsUrl(name: string, city: string | null = null): string {
    const query = city ? `${name}, ${city}` : name;
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`;
}

export function directionsUrl(lat: number, lng: number): string {
    return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
}
