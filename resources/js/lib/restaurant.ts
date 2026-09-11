type Action = 'website' | 'directions' | 'call' | 'pageview' | 'social_link_click' | 'menu';

function trackEngagement(restaurantId: number, action: Action): void {
    // Live/preview venues can carry a synthetic negative id that has no row in
    // the restaurants table. POSTing it is always a 422 (silently dropped), so
    // skip it entirely. Real persisted ids are positive. (spec-104 engagement audit)
    if (!Number.isInteger(restaurantId) || restaurantId <= 0) {
        return;
    }

    const payload = JSON.stringify({ restaurant_id: restaurantId, action });

    fetch('/api/engage', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: payload,
        keepalive: true,
    })
        .then((res) => {
            if (res.ok) {
                return;
            }
            // Retry once on rate-limit (429); other 4xx/5xx are real failures
            // that would otherwise vanish silently — surface them in the console.
            if (res.status === 429) {
                setTimeout(() => {
                    fetch('/api/engage', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: payload,
                        keepalive: true,
                    }).catch(() => {});
                }, 1000);
                return;
            }
            console.warn(`[engagement] ${action} for restaurant ${restaurantId} failed: ${res.status}`);
        })
        .catch(() => {});
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
    if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) {
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

interface AddressParts {
    address?: string | null;
    city?: string | null;
    state?: string | null;
    postal_code?: string | null;
}

/**
 * The one-line address: the stored address, then city, state and ZIP only
 * where the address doesn't already say them, so a full address ("3600
 * Presidential Blvd, Austin, TX 78719") doesn't gain ", austin, TX". An
 * all-lowercase city (a search-grid key like "austin") is title-cased.
 */
export function formatFullAddress(r: AddressParts): string {
    let address = (r.address ?? '').trim();
    const mentions = (token: string, caseSensitive: boolean) =>
        new RegExp(`(^|[^A-Za-z0-9])${token.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}($|[^A-Za-z0-9])`, caseSensitive ? '' : 'i').test(address);

    const rawCity = (r.city ?? '').trim();
    const city = rawCity === rawCity.toLowerCase() ? rawCity.replace(/(^|[\s-])\p{L}/gu, (c) => c.toUpperCase()) : rawCity;
    const state = (r.state ?? '').trim();
    const cityMissing = city !== '' && !mentions(city, false);
    // A 2-letter code only counts in capitals ("IN" the state, not "in").
    let stateMissing = state !== '' && !mentions(state, state.length === 2);

    // BizData's "Street, Number, City, 78703": the state goes before the ZIP.
    const trailingZip = /,\s*(\d{5}(?:-\d{4})?)$/;
    if (stateMissing && !cityMissing && trailingZip.test(address)) {
        address = address.replace(trailingZip, `, ${state} $1`);
        stateMissing = false;
    }

    let line = [address, cityMissing ? city : '', stateMissing ? state : ''].filter((part) => part !== '').join(', ');
    const zip = (r.postal_code ?? '').trim();
    if (zip && !address.includes(zip)) {
        line += line === '' ? zip : ` ${zip}`;
    }

    return line;
}
