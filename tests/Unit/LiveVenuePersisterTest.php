<?php

namespace Tests\Unit;

use App\Services\LiveVenuePersister;
use Database\Factories\CuisineCategoryFactory;
use Database\Factories\CuisineFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveVenuePersisterTest extends TestCase
{
    use RefreshDatabase;

    private LiveVenuePersister $persister;

    protected function setUp(): void
    {
        parent::setUp();
        $this->persister = $this->app->make(LiveVenuePersister::class);
    }

    private function venue(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Taste of Addis',
            'slug' => 'taste-of-addis',
            'address' => '123 Main St',
            'city' => 'Addisville',
            'state' => 'OR',
            'postal_code' => '97201',
            'lat' => 45.5,
            'lng' => -122.6,
            'phone' => '(555) 123-4567',
            'website_url' => 'tasteofaddis.com',
            'price_range' => '$$$',
            'photos' => [],
            'google_place_id' => 'ChIJ_live1',
            'google_rating' => 4.8,
            'google_review_count' => 120,
            'popularity_score' => 80.0,
            'features' => [],
            'is_active' => true,
        ], $overrides);
    }

    public function test_creates_new_venue_and_stamps_real_id_on_venue_array(): void
    {
        $result = $this->persister->persist($this->venue());

        $this->assertTrue($result['created']);
        $this->assertNotNull($result['restaurant']->id);
        $this->assertSame($result['restaurant']->id, $result['venue']['id']);
        $this->assertSame('Taste of Addis', $result['restaurant']->name);
        $this->assertTrue($result['restaurant']->is_active);
        $this->assertDatabaseHas('restaurants', ['google_place_id' => 'ChIJ_live1']);
    }

    public function test_updates_existing_venue_by_google_place_id_not_created(): void
    {
        $first = $this->persister->persist($this->venue());
        $id = $first['restaurant']->id;

        $result = $this->persister->persist($this->venue([
            'name' => 'Taste of Addis Renamed',
            'price_range' => '$',
        ]));

        $this->assertFalse($result['created']);
        $this->assertSame($id, $result['restaurant']->id);
        $this->assertSame('Taste of Addis Renamed', $result['restaurant']->name);
        $this->assertSame('$', $result['restaurant']->price_range);
    }

    public function test_matches_by_slug_when_no_google_place_id(): void
    {
        $first = $this->persister->persist($this->venue(['google_place_id' => null]));
        $id = $first['restaurant']->id;

        $result = $this->persister->persist($this->venue([
            'google_place_id' => null,
            'slug' => 'taste-of-addis',
            'name' => 'Taste of Addis V2',
        ]));

        $this->assertFalse($result['created']);
        $this->assertSame($id, $result['restaurant']->id);
        $this->assertSame('Taste of Addis V2', $result['restaurant']->name);
    }

    public function test_update_never_clobbers_existing_award(): void
    {
        $first = $this->persister->persist($this->venue());
        $first['restaurant']->update(['has_award' => true]);

        // A live-search source always hardcodes has_award => false; the persister
        // must exclude it on update so a real award survives. (spec-104)
        $result = $this->persister->persist($this->venue(['name' => 'Awarded & Newer']));

        $this->assertFalse($result['created']);
        $this->assertTrue($result['restaurant']->refresh()->has_award);
    }

    public function test_persisted_venue_is_normalized(): void
    {
        $result = $this->persister->persist($this->venue());

        // name stays trimmed; website gets its https scheme; phone digit-stripped.
        $this->assertSame('https://tasteofaddis.com', $result['restaurant']->website_url);
        $this->assertSame('5551234567', $result['restaurant']->phone);
        $this->assertSame('$$$', $result['restaurant']->price_range);
        $this->assertSame(4.8, $result['restaurant']->google_rating);
        $this->assertSame(45.5, $result['restaurant']->latitude);
    }

    public function test_uses_default_location_fallback_when_venue_has_no_city(): void
    {
        $result = $this->persister->persist($this->venue(['city' => null, 'state' => null]), [], [
            'city' => 'Falls City',
            'state' => 'NE',
        ]);

        $this->assertSame('Falls City', $result['restaurant']->city);
        $this->assertSame('NE', $result['restaurant']->state);
    }

    public function test_known_cuisine_ids_filters_out_unknowns(): void
    {
        $category = (new CuisineCategoryFactory)->create();
        $known = (new CuisineFactory)->create(['category_id' => $category->id]);

        $result = $this->persister->knownCuisineIds([
            'cuisines' => [
                ['id' => $known->id],
                ['id' => 999999],
                ['id' => 111111],
            ],
        ]);

        $this->assertSame([$known->id], array_values($result));
    }

    public function test_attaches_cuisine_ids_to_new_restaurant(): void
    {
        $category = (new CuisineCategoryFactory)->create();
        $cuisine = (new CuisineFactory)->create(['category_id' => $category->id]);

        $result = $this->persister->persist($this->venue(), [$cuisine->id]);

        $this->assertTrue($result['restaurant']->cuisines()->whereKey($cuisine->id)->exists());
    }
}
