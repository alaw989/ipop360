<?php

namespace App\Services;

use App\Support\ZipLocation;
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
        // Explicit URL params take priority, but only when they are in a sane
        // geographic range. Out-of-range values are attacker/typo-controlled and
        // would otherwise feed scopeNearby()'s cos() bbox math a lat near +-90,
        // producing a huge/infinite longitude delta and a full-table scan.
        if ($request->filled('lat') && $request->filled('lng')) {
            $lat = (float) $request->input('lat');
            $lng = (float) $request->input('lng');

            if ($lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0) {
                return ['lat' => $lat, 'lng' => $lng];
            }
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
     * Typeahead lookup for a city name or a US ZIP code. A ZIP resolves
     * offline from the Census gazetteer (ZipLocation) — no Photon call — and
     * is labelled with a city name from a cached reverse geocode so the
     * picker can show "Beverly Hills, CA" alongside the ZIP. Unknown ZIPs
     * return no rows rather than falling through to Photon, which has no
     * concept of a bare 5-digit number.
     *
     * @return array<int, array{city: string|null, state: string|null, country: string|null, lat: float|null, lng: float|null, zip: string|null, display: string}>
     */
    public function searchCities(string $query): array
    {
        $query = trim($query);
        if (strlen($query) < 2) {
            return [];
        }

        $zip = $this->normalizeZip($query);
        $key = 'citysearch:'.md5($zip ?? $query);

        return Cache::remember($key, now()->addDay(), function () use ($query, $zip) {
            if ($zip !== null) {
                return $this->searchByZip($zip);
            }

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
                        'zip' => null,
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

    /** "90210" or "90210-1234" → "90210"; anything else → null. */
    private function normalizeZip(string $query): ?string
    {
        return preg_match('/^(\d{5})(?:-\d{4})?$/', $query, $m) === 1 ? $m[1] : null;
    }

    /**
     * Resolve a US ZIP to its Census centroid, state and (best-effort) city.
     * Loading the gazetteer is deferred to ZipLocation, so this only costs
     * the ~2 MB parse on ZIP queries — city queries never touch it.
     *
     * @return array<int, array{city: string|null, state: string|null, country: string|null, lat: float|null, lng: float|null, zip: string|null, display: string}>
     */
    private function searchByZip(string $zip): array
    {
        $centroid = ZipLocation::centroid($zip);
        if ($centroid === null) {
            return [];
        }

        $state = ZipLocation::state($zip);

        $city = null;
        $reverse = $this->reverseGeocode($centroid['lat'], $centroid['lng']);
        if ($reverse !== null) {
            $city = $reverse['city'] ?? null;
        }

        return [[
            'city' => $city,
            'state' => $state,
            'country' => 'US',
            'lat' => $centroid['lat'],
            'lng' => $centroid['lng'],
            'zip' => $zip,
            'display' => $city === null ? '' : 'ZIP '.$zip,
        ]];
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
