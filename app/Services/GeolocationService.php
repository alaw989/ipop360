<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeolocationService
{
    /*
     * NOTE: This service uses Laravel's Cache facade (Cache::remember) rather than
     * ExternalApiCache because geocoding results are NOT quota-bound (free APIs like
     * Nominatim/Photon have no monthly limits) and have different invalidation needs
     * (city coordinates rarely change, so we cache for weeks). This separation is
     * intentional — do NOT unify with ExternalApiCache, as cache misses here do NOT
     * burn SerpApi quota. See config/restaurant-finder.php cache section for the
     * full explanation of the two-store architecture.
     */

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function resolveCoordinates(Request $request): ?array
    {
        // Explicit URL params take priority
        if ($request->filled('lat') && $request->filled('lng')) {
            return [
                'lat' => (float) $request->input('lat'),
                'lng' => (float) $request->input('lng'),
            ];
        }

        // Check session for previously-stored coordinates
        $sessionCoords = $request->session()->get('user_coords');
        if ($sessionCoords !== null) {
            return $sessionCoords;
        }

        $ip = $request->ip();
        if ($ip === null) {
            return null;
        }

        return $this->ipLookup($ip);
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function ipLookup(string $ip): ?array
    {
        $full = $this->ipLookupFull($ip);
        if ($full === null) {
            return null;
        }

        return ['lat' => $full['lat'], 'lng' => $full['lng']];
    }

    /**
     * @return array<int, array{city: string|null, state: string|null, country: string|null, lat: float|null, lng: float|null, display: string}>
     */
    public function searchCities(string $query): array
    {
        if (strlen($query) < 2) {
            return [];
        }

        $key = 'citysearch:'.md5($query);

        return Cache::remember($key, now()->addDay(), function () use ($query) {
            try {
                // Photon (Komoot) — free, no API key, built for autocomplete
                $response = Http::timeout(5)
                    ->get('https://photon.komoot.io/api/', [
                        'q' => $query,
                        'limit' => 10,
                    ]);

                if ($response->failed()) {
                    return [];
                }

                $data = $response->json();
                $features = $data['features'] ?? [];

                /** @var array<int, array<string, mixed>> $features */
                return collect($features)
                    ->filter(fn ($f) => in_array(
                        $f['properties']['osm_value'] ?? '',
                        ['city', 'town', 'village', 'hamlet', 'municipality']
                    ))
                    ->filter(fn ($f) => in_array(
                        strtoupper($f['properties']['countrycode'] ?? ''),
                        ['US', 'CA']
                    ))
                    ->map(fn ($f) => [
                        'city' => $f['properties']['name'] ?? null,
                        'state' => $f['properties']['state'] ?? null,
                        'country' => $f['properties']['countrycode'] ?? null,
                        'lat' => $f['geometry']['coordinates'][1] ?? null,
                        'lng' => $f['geometry']['coordinates'][0] ?? null,
                        'display' => trim(collect([
                            $f['properties']['name'] ?? null,
                            $f['properties']['state'] ?? null,
                            $f['properties']['country'] ?? null,
                        ])->filter()->implode(', ')),
                    ])
                    ->filter(fn ($r) => $r['city'] !== null && $r['lat'] !== null)
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                Log::debug('City search failed', ['query' => $query, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    public function forwardGeocode(string $city, ?string $state): ?array
    {
        $query = $state ? "{$city}, {$state}" : $city;
        $key = 'fwdgeo:'.md5($query);

        return Cache::remember($key, now()->addWeek(), function () use ($query) {
            try {
                $response = Http::timeout(3)
                    ->withHeaders(['User-Agent' => 'iPop360/1.0'])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $query,
                        'format' => 'json',
                        'limit' => 1,
                        'addressdetails' => 1,
                    ]);

                if ($response->failed() || empty($response->json())) {
                    return null;
                }

                $data = $response->json()[0];

                return [
                    'lat' => (float) $data['lat'],
                    'lng' => (float) $data['lon'],
                ];
            } catch (\Throwable $e) {
                Log::debug('Forward geocoding failed', ['query' => $query, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * @return array{city: string|null, state: string|null}|null
     */
    public function reverseGeocode(float $lat, float $lng): ?array
    {
        $key = sprintf('revgeo:%.4f:%.4f', $lat, $lng);

        return Cache::remember($key, now()->addWeek(), function () use ($lat, $lng) {
            try {
                $response = Http::timeout(3)
                    ->withHeaders(['User-Agent' => 'iPop360/1.0'])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat,
                        'lon' => $lng,
                        'format' => 'json',
                        'addressdetails' => 1,
                        'zoom' => 10,
                    ]);

                if ($response->failed()) {
                    return null;
                }

                $data = $response->json();
                $address = $data['address'] ?? [];

                $city = $address['city'] ?? $address['town'] ?? $address['village'] ?? $address['municipality'] ?? null;
                $state = $address['state'] ?? $address['region'] ?? null;

                if ($city === null && $state === null) {
                    return null;
                }

                return ['city' => $city, 'state' => $state];
            } catch (\Throwable $e) {
                Log::debug('Reverse geocoding failed', ['lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * @return array{lat: float, lng: float, city: string|null, region: string|null}|null
     */
    public function ipLookupFull(string $ip): ?array
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return null;
        }

        $cacheKey = "geo_full:{$ip}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        try {
            $response = Http::timeout(3)->get("https://ipapi.co/{$ip}/json/");

            if ($response->failed()) {
                // Short negative cache: a rate limit or transient outage
                // shouldn't lock this IP out of location resolution for a
                // full day — retry again in a few minutes instead.
                Cache::put($cacheKey, 'miss', now()->addMinutes(5));

                return null;
            }

            $data = $response->json();

            if (isset($data['latitude'], $data['longitude'])) {
                $result = [
                    'lat' => (float) $data['latitude'],
                    'lng' => (float) $data['longitude'],
                    'city' => $data['city'] ?? null,
                    'region' => $data['region'] ?? null,
                ];
                Cache::put($cacheKey, $result, now()->addDay());

                return $result;
            }
        } catch (\Throwable $e) {
            Log::debug('IP geolocation lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            Cache::put($cacheKey, 'miss', now()->addMinutes(5));
        }

        return null;
    }
}
