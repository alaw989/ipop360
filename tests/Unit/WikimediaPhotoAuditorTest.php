<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use App\Services\WikidataService;
use App\Services\WikimediaPhotoAuditor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * WikimediaPhotoAuditor classifies a stored Wikimedia photo as verified (the
 * Commons file is geotagged near the pin, or a Wikidata item within ~150 m
 * carries the same P18 image) or unverified (a name-only match — the "Lost &
 * Found" bin, "Ela" the person). Report-only: the auditor never writes.
 */
class WikimediaPhotoAuditorTest extends TestCase
{
    private const PHOTO = 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a3/Test_Eatery.jpg/800px-Test_Eatery.jpg';

    private const PHOTO_PLAIN = 'https://upload.wikimedia.org/wikipedia/commons/a/a3/Test_Eatery.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<int, array{name: string, lat: float, lng: float, image: string|null}>  $venues
     */
    private function auditor(array $venues = []): WikimediaPhotoAuditor
    {
        $wikidata = Mockery::mock(WikidataService::class);
        $wikidata->shouldReceive('findRestaurantImagesInBox')->andReturn($venues);

        return new WikimediaPhotoAuditor($wikidata);
    }

    private function restaurant(string $photo, ?float $lat = 30.25, ?float $lng = -97.75): Restaurant
    {
        return new Restaurant([
            'name' => 'Test Eatery',
            'photo_url' => $photo,
            'latitude' => $lat,
            'longitude' => $lng,
            'photo_source' => 'wikimedia',
        ]);
    }

    private function fakeCommonsCoordinates(?float $lat, ?float $lng): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response([
                'query' => [
                    'pages' => [
                        '123' => $lat === null
                            ? ['pageid' => -1, 'title' => 'File:Test Eatery.jpg', 'missing' => '']
                            : ['pageid' => 123, 'title' => 'File:Test Eatery.jpg', 'coordinates' => [['lat' => $lat, 'lon' => $lng]]],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_a_geotagged_commons_file_near_the_pin_is_verified(): void
    {
        $this->fakeCommonsCoordinates(30.25, -97.75);

        $result = $this->auditor()->audit($this->restaurant(self::PHOTO));

        $this->assertSame(WikimediaPhotoAuditor::VERDICT_COMMONS, $result['verdict']);
        $this->assertSame(0, $result['distance_m']);
    }

    public function test_a_far_commons_file_falls_through_to_unverified(): void
    {
        // ~5 km away: not the venue's own photo.
        $this->fakeCommonsCoordinates(30.295, -97.75);

        $result = $this->auditor()->audit($this->restaurant(self::PHOTO));

        $this->assertSame(WikimediaPhotoAuditor::VERDICT_UNVERIFIED, $result['verdict']);
    }

    public function test_a_nearby_wikidata_item_carrying_the_same_file_verifies_it(): void
    {
        $this->fakeCommonsCoordinates(null, null);

        $venues = [[
            'name' => 'Test Eatery',
            'lat' => 30.2505,
            'lng' => -97.75,
            'image' => self::PHOTO_PLAIN,
        ]];

        $result = $this->auditor($venues)->audit($this->restaurant(self::PHOTO));

        $this->assertSame(WikimediaPhotoAuditor::VERDICT_WIKIDATA, $result['verdict']);
    }

    public function test_a_nearby_wikidata_item_with_a_different_photo_does_not_verify(): void
    {
        $this->fakeCommonsCoordinates(null, null);

        $venues = [[
            'name' => 'Test Eatery',
            'lat' => 30.2505,
            'lng' => -97.75,
            'image' => 'https://upload.wikimedia.org/wikipedia/commons/a/a3/Something_Else.jpg',
        ]];

        $result = $this->auditor($venues)->audit($this->restaurant(self::PHOTO));

        $this->assertSame(WikimediaPhotoAuditor::VERDICT_UNVERIFIED, $result['verdict']);
    }

    public function test_a_restaurant_without_coordinates_is_uncheckable(): void
    {
        Http::fake();

        $result = $this->auditor()->audit($this->restaurant(self::PHOTO, null, null));

        $this->assertSame(WikimediaPhotoAuditor::VERDICT_UNCHECKABLE, $result['verdict']);
        Http::assertNothingSent();
    }

    public function test_a_non_wikimedia_url_is_uncheckable(): void
    {
        Http::fake();

        $result = $this->auditor()->audit($this->restaurant('https://cdn.example.com/photo.jpg'));

        $this->assertSame(WikimediaPhotoAuditor::VERDICT_UNCHECKABLE, $result['verdict']);
        Http::assertNothingSent();
    }

    public function test_a_failed_commons_lookup_is_uncheckable_not_unverified(): void
    {
        // A transient Commons failure must never read as "unverified": an
        // --apply run would otherwise quarantine a genuinely verified photo.
        Http::fake([
            'commons.wikimedia.org/*' => Http::response('service unavailable', 503),
        ]);

        $wikidata = Mockery::mock(WikidataService::class);
        $wikidata->shouldNotReceive('findRestaurantImagesInBox');

        $result = (new WikimediaPhotoAuditor($wikidata))->audit($this->restaurant(self::PHOTO));

        $this->assertSame(WikimediaPhotoAuditor::VERDICT_UNCHECKABLE, $result['verdict']);
    }

    public function test_preload_commons_batches_titles_and_caches_the_results(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response([
                'query' => [
                    'pages' => [
                        '1' => ['pageid' => 1, 'title' => 'File:A.jpg', 'coordinates' => [['lat' => 30.0, 'lon' => -97.0]]],
                        '2' => ['pageid' => 2, 'title' => 'File:B.jpg'],
                    ],
                ],
            ], 200),
        ]);

        $auditor = $this->auditor();
        $stats = $auditor->preloadCommons(['A.jpg', 'B.jpg']);

        $this->assertSame(2, $stats['fetched']);
        Http::assertSentCount(1);

        $this->assertSame('coords', $auditor->commonsLookup('A.jpg')['status']);
        $this->assertSame('none', $auditor->commonsLookup('B.jpg')['status']);
        Http::assertSentCount(1);
    }

    public function test_preload_commons_stops_on_rate_limit(): void
    {
        Http::fake([
            'commons.wikimedia.org/*' => Http::response('slow down', 429),
        ]);

        $auditor = $this->auditor();
        $stats = $auditor->preloadCommons(['A.jpg', 'B.jpg']);

        $this->assertSame(2, $stats['failed']);
        $this->assertSame('failed', $auditor->commonsLookup('A.jpg')['status']);
        Http::assertSentCount(1);
    }

    public function test_preload_commons_marks_titles_after_the_rate_limited_batch_failed(): void
    {
        // 51 names = one good batch of 50, then a second batch that gets a 429.
        $names = array_map(fn (int $i): string => "Photo {$i}.jpg", range(1, 51));

        Http::fakeSequence()
            ->push(['query' => ['pages' => []]], 200)
            ->push('slow down', 429);

        $auditor = $this->auditor();
        $stats = $auditor->preloadCommons($names);

        $this->assertSame(50, $stats['fetched']);
        $this->assertSame(1, $stats['failed']);
        Http::assertSentCount(2);

        // The title in the throttled batch is failed, and no later lookup falls
        // back to a single request (which is what made a full run take 22 min).
        $this->assertSame('failed', $auditor->commonsLookup('Photo 51.jpg')['status']);
        Http::assertSentCount(2);
    }

    public function test_preload_commons_retries_a_transient_error_and_continues(): void
    {
        $names = array_map(fn (int $i): string => "Photo {$i}.jpg", range(1, 51));

        Http::fakeSequence()
            ->push(['query' => ['pages' => []]], 200)   // chunk 1
            ->push('bad gateway', 503)                   // chunk 2, first attempt
            ->push(['query' => ['pages' => []]], 200);   // chunk 2, retry

        $stats = $this->auditor()->preloadCommons($names);

        $this->assertSame(51, $stats['fetched']);
        $this->assertSame(0, $stats['failed']);
    }

    public function test_preload_commons_fails_only_the_chunk_with_a_persistent_error(): void
    {
        $names = array_map(fn (int $i): string => "Photo {$i}.jpg", range(1, 51));

        Http::fakeSequence()
            ->push(['query' => ['pages' => []]], 200) // chunk 1
            ->push('bad gateway', 503)                // chunk 2, attempt 1
            ->push('bad gateway', 503)                // chunk 2, attempt 2
            ->push('bad gateway', 503);               // chunk 2, attempt 3

        $stats = $this->auditor()->preloadCommons($names);

        $this->assertSame(50, $stats['fetched']);
        $this->assertSame(1, $stats['failed']);
    }
}
