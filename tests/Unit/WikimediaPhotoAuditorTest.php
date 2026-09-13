<?php

namespace Tests\Unit;

use App\Models\Restaurant;
use App\Services\WikidataService;
use App\Services\WikimediaPhotoAuditor;
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
}
