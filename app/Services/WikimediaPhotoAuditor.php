<?php

namespace App\Services;

use App\Models\Restaurant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Classifies a stored Wikimedia photo as a real picture of the venue or a
 * name-only match (the "Lost & Found" bin, "Ela" the person).
 *
 * A photo passes when either:
 *  - its Commons file description page is geotagged within ~150 m of the pin
 *    (Commons `prop=coordinates`), or
 *  - a Wikidata item within ~150 m carries the same file as its P18 image.
 *
 * Report-only by design: the auditor never writes. Removal is a separate,
 * operator-approved decision (backlog goal 19).
 */
class WikimediaPhotoAuditor
{
    public const VERDICT_COMMONS = 'verified_commons';

    public const VERDICT_WIKIDATA = 'verified_wikidata';

    public const VERDICT_UNVERIFIED = 'unverified';

    public const VERDICT_UNCHECKABLE = 'uncheckable';

    private const COMMONS_ENDPOINT = 'https://commons.wikimedia.org/w/api.php';

    private const USER_AGENT = 'iPop360/1.0';

    /** Pass distance: ~150 m (the Moose's Tooth verification radius). */
    private const MAX_DISTANCE_KM = 0.15;

    /** Wikidata proximity box (±degrees), matching WikidataService. */
    private const BOX_PADDING = 0.01;

    public function __construct(private WikidataService $wikidata) {}

    /**
     * @return array{verdict: string, distance_m: int|null, title: string|null}
     */
    public function audit(Restaurant $restaurant): array
    {
        $photo = trim((string) $restaurant->photo_url);
        $filename = $photo === '' ? null : $this->fileNameFromUrl($photo);

        if ($filename === null || $restaurant->latitude === null || $restaurant->longitude === null) {
            return $this->result(self::VERDICT_UNCHECKABLE, null, $filename);
        }

        $lat = (float) $restaurant->latitude;
        $lng = (float) $restaurant->longitude;

        $coords = $this->commonsCoordinates($filename);
        if ($coords !== null) {
            $km = $this->haversineKm($lat, $lng, $coords['lat'], $coords['lng']);
            if ($km <= self::MAX_DISTANCE_KM) {
                return $this->result(self::VERDICT_COMMONS, $this->meters($km), $filename);
            }
        }

        $venues = $this->wikidata->findRestaurantImagesInBox(
            $lat - self::BOX_PADDING,
            $lng - self::BOX_PADDING,
            $lat + self::BOX_PADDING,
            $lng + self::BOX_PADDING,
        );

        foreach ($venues as $venue) {
            $image = $venue['image'] ?? null;
            if (! is_string($image) || $image === '' || ! $this->sameFile($image, $photo)) {
                continue;
            }
            $km = $this->haversineKm($lat, $lng, (float) $venue['lat'], (float) $venue['lng']);
            if ($km <= self::MAX_DISTANCE_KM) {
                return $this->result(self::VERDICT_WIKIDATA, $this->meters($km), $filename);
            }
        }

        return $this->result(self::VERDICT_UNVERIFIED, null, $filename);
    }

    /**
     * The Commons file coordinates, or null when the file has none / is missing.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function commonsCoordinates(string $filename): ?array
    {
        try {
            $response = Http::timeout(8)
                ->withUserAgent(self::USER_AGENT)
                ->get(self::COMMONS_ENDPOINT, [
                    'action' => 'query',
                    'titles' => 'File:'.$filename,
                    'prop' => 'coordinates',
                    'format' => 'json',
                    'origin' => '*',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $pages = $response->json()['query']['pages'] ?? [];
            foreach (is_array($pages) ? $pages : [] as $page) {
                $coords = is_array($page) ? ($page['coordinates'][0] ?? null) : null;
                if (is_array($coords) && isset($coords['lat'], $coords['lon'])) {
                    return ['lat' => (float) $coords['lat'], 'lng' => (float) $coords['lon']];
                }
            }
        } catch (\Throwable $e) {
            Log::debug('Commons file coordinates lookup failed', [
                'title' => 'File:'.$filename,
                'message' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * The Commons file name a Wikimedia URL points at, or null when the URL is
     * not a Wikimedia file. Handles both thumb
     * (`…/thumb/a/a3/Name.jpg/800px-Name.jpg`) and original
     * (`…/a/a3/Name.jpg`) layouts.
     */
    public function fileNameFromUrl(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || (! str_ends_with($host, 'wikimedia.org') && ! str_ends_with($host, 'wikipedia.org'))) {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', (string) parse_url($url, PHP_URL_PATH)),
            fn (string $segment): bool => $segment !== '',
        ));
        if ($segments === []) {
            return null;
        }

        $candidates = [end($segments)];
        if (count($segments) >= 2) {
            $candidates[] = $segments[count($segments) - 2];
        }

        foreach ($candidates as $candidate) {
            $name = (string) preg_replace('/^\d+px-/', '', rawurldecode((string) $candidate));
            if (preg_match('/\.(jpe?g|png|gif|webp|tiff?|svg)$/i', $name) === 1) {
                return str_replace('_', ' ', $name);
            }
        }

        return null;
    }

    private function sameFile(string $a, string $b): bool
    {
        $nameA = $this->fileNameFromUrl($a);
        $nameB = $this->fileNameFromUrl($b);

        return $nameA !== null && $nameB !== null && mb_strtolower($nameA) === mb_strtolower($nameB);
    }

    /**
     * @return array{verdict: string, distance_m: int|null, title: string|null}
     */
    private function result(string $verdict, ?int $distanceM, ?string $filename): array
    {
        return [
            'verdict' => $verdict,
            'distance_m' => $distanceM,
            'title' => $filename === null ? null : 'File:'.$filename,
        ];
    }

    private function meters(float $km): int
    {
        return (int) round($km * 1000);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * asin(min(1.0, sqrt($a)));
    }
}
