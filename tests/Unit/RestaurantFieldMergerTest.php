<?php

namespace Tests\Unit;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Services\RestaurantFieldMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins what a source record may change on an existing restaurant row: blanks
 * are filled, stored values are never nulled or overwritten, a positive rating
 * refreshes as a pair, and quarantined values never come back.
 */
class RestaurantFieldMergerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function merge(Restaurant $existing, array $incoming): array
    {
        return (new RestaurantFieldMerger)->forExisting($existing, $incoming);
    }

    public function test_sparse_record_changes_nothing_on_a_populated_row(): void
    {
        $existing = Restaurant::factory()->create([
            'phone' => '5125550100',
            'website_url' => 'https://example-cafe.test',
            'photo_url' => 'https://example-cafe.test/front.jpg',
            'google_rating' => 4.4,
            'google_review_count' => 812,
        ]);

        $merged = $this->merge($existing, [
            'name' => $existing->name,
            'address' => null,
            'phone' => null,
            'website_url' => null,
            'photo_url' => null,
            'photo_source' => null,
            'price_range' => null,
            'description' => null,
            'google_rating' => null,
            'google_review_count' => 0,
            'yelp_rating' => null,
            'yelp_review_count' => 0,
            'features' => null,
            'is_active' => true,
            'source' => 'bizdata',
        ]);

        $this->assertArrayNotHasKey('phone', $merged);
        $this->assertArrayNotHasKey('google_rating', $merged);
        $this->assertArrayNotHasKey('google_review_count', $merged);
        $this->assertArrayNotHasKey('is_active', $merged);
        $this->assertArrayNotHasKey('photo_source', $merged);
    }

    public function test_fills_blank_fields_only(): void
    {
        $existing = Restaurant::factory()->create(['phone' => null, 'website_url' => 'https://kept.test']);

        $merged = $this->merge($existing, [
            'phone' => '5125550199',
            'website_url' => 'https://other.test',
        ]);

        $this->assertSame('5125550199', $merged['phone']);
        $this->assertArrayNotHasKey('website_url', $merged);
    }

    public function test_address_or_postal_code_from_another_location_is_not_filled(): void
    {
        $existing = Restaurant::factory()->create(['address' => null, 'postal_code' => null, 'latitude' => 37.7936, 'longitude' => -122.3950]);

        $elsewhere = $this->merge($existing, ['address' => 'Virginia Beach Boulevard, 4554, Virginia Beach, 23462', 'postal_code' => '23462']);
        $local = $this->merge($existing, ['address' => '1 Market St, San Francisco, CA 94105', 'postal_code' => '94105']);

        $this->assertSame([], $elsewhere);
        $this->assertSame(['address' => '1 Market St, San Francisco, CA 94105', 'postal_code' => '94105'], $local);
    }

    public function test_positive_rating_refreshes_the_pair(): void
    {
        $existing = Restaurant::factory()->create(['google_rating' => 4.1, 'google_review_count' => 300]);

        $merged = $this->merge($existing, ['google_rating' => 4.3, 'google_review_count' => 355]);

        $this->assertSame(4.3, $merged['google_rating']);
        $this->assertSame(355, $merged['google_review_count']);
    }

    public function test_never_reactivates_or_renames(): void
    {
        $existing = Restaurant::factory()->create(['is_active' => false, 'has_award' => true]);

        $merged = $this->merge($existing, [
            'is_active' => true,
            'has_award' => false,
            'slug' => 'new-slug',
            'name' => 'SHOUTY NAME',
        ]);

        $this->assertSame([], $merged);
    }

    public function test_quarantined_value_is_not_filled_back(): void
    {
        $existing = Restaurant::factory()->create(['website_url' => null]);
        FieldQuarantine::create([
            'restaurant_id' => $existing->id,
            'field' => 'website_url',
            'old_value' => 'https://www.merriam-webster.com/dictionary/cafe',
            'reason' => 'reference_domain',
            'quarantined_at' => now(),
        ]);

        $merged = $this->merge($existing, ['website_url' => 'https://www.merriam-webster.com/dictionary/cafe']);

        $this->assertArrayNotHasKey('website_url', $merged);
    }

    public function test_quarantined_rating_pair_is_not_restored_but_a_new_reading_is(): void
    {
        $existing = Restaurant::factory()->create(['google_rating' => null, 'google_review_count' => 0]);
        foreach (['google_rating' => '4.6', 'google_review_count' => '1204'] as $field => $old) {
            FieldQuarantine::create([
                'restaurant_id' => $existing->id,
                'field' => $field,
                'old_value' => $old,
                'reason' => 'copied_rating',
                'quarantined_at' => now(),
            ]);
        }

        $this->assertSame([], $this->merge($existing, ['google_rating' => 4.6, 'google_review_count' => 1204]));
        $this->assertSame(
            ['google_rating' => 4.2, 'google_review_count' => 57],
            $this->merge($existing, ['google_rating' => 4.2, 'google_review_count' => 57])
        );
    }

    public function test_stable_photo_replaces_a_transient_one_with_its_source(): void
    {
        $existing = Restaurant::factory()->create([
            'photo_url' => 'https://lh3.googleusercontent.com/gps-cs-s/OLD=w400',
            'photo_source' => 'google_thumbnail',
        ]);

        $merged = $this->merge($existing, [
            'photo_url' => 'https://upload.wikimedia.org/front.jpg',
            'photo_source' => 'wikimedia',
        ]);

        $this->assertSame('https://upload.wikimedia.org/front.jpg', $merged['photo_url']);
        $this->assertSame('wikimedia', $merged['photo_source']);
    }

    public function test_gallery_is_replaced_only_by_a_superset(): void
    {
        $existing = Restaurant::factory()->create(['photos' => ['https://a.test/1.jpg', 'https://a.test/2.jpg']]);

        $this->assertArrayNotHasKey('photos', $this->merge($existing, ['photos' => ['https://b.test/9.jpg']]));
        $this->assertSame(
            ['https://a.test/1.jpg', 'https://a.test/2.jpg', 'https://b.test/9.jpg'],
            $this->merge($existing, ['photos' => ['https://a.test/1.jpg', 'https://a.test/2.jpg', 'https://b.test/9.jpg']])['photos']
        );
    }
}
