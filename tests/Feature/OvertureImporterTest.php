<?php

namespace Tests\Feature;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Services\FieldQuarantineService;
use App\Services\Overture\OvertureImporter;
use App\Support\ZipLocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * OvertureImporter::matchBlock — matching by location and the fill rules
 * (empty fields only, provenance recorded, closures reversible).
 */
class OvertureImporterTest extends TestCase
{
    use RefreshDatabase;

    private const RELEASE = '2026-08-19.0';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
        // Stubs match in registration order: the bucket listing before the
        // catch-all that answers profile reachability checks.
        Http::fake([
            'overturemaps-us-west-2.s3.amazonaws.com/*' => Http::response(
                '<ListBucketResult><CommonPrefixes><Prefix>release/2026-07-22.0/</Prefix></CommonPrefixes>'
                .'<CommonPrefixes><Prefix>release/2026-08-19.0/</Prefix></CommonPrefixes>'
                .'<CommonPrefixes><Prefix>release/2026-06-18.1/</Prefix></CommonPrefixes></ListBucketResult>'
            ),
            '*' => Http::response('', 200),
        ]);
    }

    private function importer(): OvertureImporter
    {
        return $this->app->make(OvertureImporter::class);
    }

    /** @param array<string, mixed> $overrides */
    private function restaurant(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->create(array_merge([
            'name' => 'Blue Heron Bistro',
            'city' => 'Tacoma',
            'state' => 'WA',
            'latitude' => 47.2529,
            'longitude' => -122.4443,
            'phone' => null,
            'address' => null,
            'website_url' => null,
            'is_active' => true,
            'social_links_count' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function place(array $overrides = []): array
    {
        return array_merge([
            'id' => '08f28d4c-overture-0001',
            'name' => 'Blue Heron Bistro',
            'category' => 'restaurant',
            'confidence' => 0.97,
            'operating_status' => 'open',
            'websites' => ['https://blueheronbistro.example/'],
            'phones' => ['+1 253-555-0142'],
            'socials' => ['https://www.facebook.com/BlueHeronBistro'],
            'street' => '410 Harbor Way',
            'city' => 'Tacoma',
            'region' => 'WA',
            'postcode' => '98402',
            'lat' => 47.2530,
            'lng' => -122.4444,
            'datasets' => ['meta', 'Microsoft', 'foursquare'],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $places
     * @return array<string, int>
     */
    private function match(Restaurant $restaurant, array $places, bool $apply = true): array
    {
        return $this->importer()->matchBlock(new Collection([$restaurant]), $places, self::RELEASE, $apply);
    }

    public function test_matched_restaurant_gets_corroboration_and_empty_fields_filled_with_provenance(): void
    {
        $r = $this->restaurant();

        $stats = $this->match($r, [$this->place()]);

        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertSame(1, $stats['matched']);
        $this->assertSame('08f28d4c-overture-0001', $fresh->overture_id);
        $this->assertSame(0.97, $fresh->overture_confidence);
        $this->assertSame(3, $fresh->overture_sources);
        $this->assertSame('open', $fresh->overture_status);
        $this->assertSame('2535550142', $fresh->phone);
        $this->assertSame('410 Harbor Way, Tacoma, WA 98402', $fresh->address);
        $this->assertSame('https://blueheronbistro.example/', $fresh->website_url);
        $this->assertNull($fresh->website_identity, 'filled websites are identity-checked later');
        $this->assertSame(['phone' => 'overture:'.self::RELEASE, 'address' => 'overture:'.self::RELEASE, 'website_url' => 'overture:'.self::RELEASE], $fresh->field_sources);
        $this->assertSame('https://www.facebook.com/BlueHeronBistro', $fresh->socialLinks()->value('url'));
        $this->assertSame(1, $fresh->social_links_count);
    }

    public function test_filled_website_is_queued_for_identity_check_even_after_a_quarantine(): void
    {
        // A quarantined website leaves website_verified_at stamped; the new URL
        // must not inherit it, or verify-websites skips it for a month.
        $r = $this->restaurant(['website_verified_at' => now()->subDay(), 'website_identity' => null]);

        $this->match($r, [$this->place()]);

        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertSame('https://blueheronbistro.example/', $fresh->website_url);
        $this->assertNull($fresh->website_verified_at, 'the filled website is queued for the next verify-websites run');
    }

    public function test_existing_values_are_never_overwritten_and_conflicts_are_counted(): void
    {
        $r = $this->restaurant(['phone' => '2535559999', 'address' => '1 Other St', 'website_url' => 'https://own.example']);

        $stats = $this->match($r, [$this->place()]);

        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertSame('2535559999', $fresh->phone);
        $this->assertSame('1 Other St', $fresh->address);
        $this->assertSame('https://own.example', $fresh->website_url);
        $this->assertSame(1, $stats['phone_conflicts']);
    }

    public function test_address_copied_from_another_location_is_replaced_with_the_places(): void
    {
        // A Tacoma location carrying the Virginia Beach location's address.
        $copied = 'Virginia Beach Boulevard, 4554, Virginia Beach, 23462';
        $r = $this->restaurant(['address' => $copied, 'postal_code' => '23462']);

        $report = $this->match($r, [$this->place()], apply: false);
        $this->assertSame(1, $report['address_corrected']);
        $this->assertSame($copied, $r->fresh()?->address, 'report-only writes nothing');

        $this->match($r, [$this->place()]);

        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertSame('410 Harbor Way, Tacoma, WA 98402', $fresh->address);
        $this->assertSame('98402', $fresh->postal_code);
        $this->assertSame('overture:'.self::RELEASE, $fresh->field_sources['address'] ?? null);
        $quarantined = FieldQuarantine::query()->where('restaurant_id', $r->id)->where('reason', 'address_far_from_location')->pluck('old_value', 'field')->all();
        $this->assertSame(['address' => $copied, 'postal_code' => '23462'], $quarantined);

        // Restoring can't bring back half the old address next to the new one.
        /** @var PendingCommand $restore */
        $restore = $this->artisan('restaurants:integrity', ['--restore' => 'address_far_from_location']);
        $restore->assertSuccessful()->run();
        $this->assertSame('98402', $fresh->fresh()?->postal_code);
    }

    public function test_address_in_the_places_own_zip_is_left_alone(): void
    {
        // Key West's ZCTA centroid sits ~12 km out at sea from Duval St, so the
        // ZIP alone reads as "far"; the place at the pin confirms the address.
        $keyWest = ['name' => "Willi T's", 'latitude' => 24.5550, 'longitude' => -81.8020, 'city' => 'Key West', 'state' => 'FL'];
        $r = $this->restaurant($keyWest + ['address' => '525 Duval St, Key West, FL 33040']);
        $this->assertTrue(ZipLocation::isFarFrom('33040', 24.5550, -81.8020));

        $stats = $this->match($r, [$this->place([
            'name' => "Willi T's", 'street' => '525 Duval St', 'city' => 'Key West', 'region' => 'FL', 'postcode' => '33040',
            'lat' => 24.5551, 'lng' => -81.8021, 'phones' => [],
        ])]);

        $this->assertSame(0, $stats['address_corrected']);
        $this->assertSame('525 Duval St, Key West, FL 33040', $r->fresh()?->address);
    }

    public function test_blank_address_is_never_filled_with_a_zip_far_from_the_pin(): void
    {
        $r = $this->restaurant();

        $stats = $this->match($r, [$this->place(['street' => '4554 Virginia Beach Blvd', 'city' => 'Virginia Beach', 'region' => 'VA', 'postcode' => '23462'])]);

        $this->assertSame(0, $stats['address_filled']);
        $this->assertNull($r->fresh()?->address);
    }

    public function test_out_of_state_phone_and_blocked_website_are_not_filled(): void
    {
        $r = $this->restaurant();

        $this->match($r, [$this->place(['phones' => ['(212) 555-0100'], 'websites' => ['https://www.yelp.com/biz/blue-heron']])]);

        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertNull($fresh->phone, 'a New York number is not a Tacoma restaurant\'s phone');
        $this->assertNull($fresh->website_url);
    }

    public function test_low_confidence_place_corroborates_but_fills_nothing(): void
    {
        $r = $this->restaurant();

        $this->match($r, [$this->place(['confidence' => 0.2])]);

        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertSame('08f28d4c-overture-0001', $fresh->overture_id);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->address);
    }

    public function test_permanently_closed_place_deactivates_reversibly(): void
    {
        $r = $this->restaurant();

        $stats = $this->match($r, [$this->place(['operating_status' => 'permanently_closed'])]);

        $this->assertSame(1, $stats['closed']);
        $this->assertFalse((bool) Restaurant::query()->whereKey($r->id)->value('is_active'));
        $entry = FieldQuarantine::query()->where('restaurant_id', $r->id)->firstOrFail();
        $this->assertSame('closed_per_overture', $entry->reason);

        $this->assertTrue($this->app->make(FieldQuarantineService::class)->restore($entry));
        $this->assertTrue((bool) Restaurant::query()->whereKey($r->id)->value('is_active'));
    }

    public function test_places_too_far_or_differently_named_do_not_match(): void
    {
        $r = $this->restaurant(['phone' => '2535550142']);

        $stats = $this->match($r, [
            $this->place(['lat' => 47.2600, 'phones' => []]),                     // ~800 m away, no phone
            $this->place(['name' => 'Harbor Pizza', 'phones' => ['2535551111']]), // next door, other name + phone
        ]);

        $this->assertSame(0, $stats['matched']);
        $this->assertNull(Restaurant::query()->whereKey($r->id)->value('overture_id'));
    }

    public function test_same_phone_nearby_matches_despite_a_different_name(): void
    {
        $r = $this->restaurant(['name' => 'Wagaya', 'phone' => '2535550142']);

        $stats = $this->match($r, [$this->place(['name' => 'Wagaya - Westside'])]);

        $this->assertSame(1, $stats['matched']);
    }

    public function test_report_only_writes_nothing(): void
    {
        $r = $this->restaurant();

        $stats = $this->match($r, [$this->place()], apply: false);

        $this->assertSame(1, $stats['matched']);
        $this->assertSame(1, $stats['phone_filled']);
        $this->assertSame(1, $stats['socials_added']);
        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertNull($fresh->overture_id);
        $this->assertNull($fresh->phone);
        $this->assertSame(0, $fresh->socialLinks()->count());
    }

    public function test_skip_socials_still_corroborates_but_adds_no_profiles(): void
    {
        $r = $this->restaurant();

        $stats = $this->importer()->matchBlock(new Collection([$r]), [$this->place()], self::RELEASE, apply: true, socials: false);

        $this->assertSame(0, $stats['socials_added']);
        $this->assertSame(0, $r->socialLinks()->count());
        $this->assertSame('08f28d4c-overture-0001', Restaurant::query()->whereKey($r->id)->value('overture_id'));
    }

    public function test_latest_release_is_read_from_the_bucket_listing(): void
    {
        $this->assertSame('2026-08-19.0', $this->importer()->latestRelease());
    }
}
