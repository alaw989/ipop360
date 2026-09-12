<?php

namespace Tests\Feature;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * restaurants:integrity is report-only by default; --apply moves provably
 * wrong values into field_quarantine; --restore=<reason> undoes a detector.
 */
class RestaurantIntegrityCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
        Config::set('restaurant-finder.data_integrity.social_brand_min_restaurants', 3);
        Http::fake(['*' => Http::response('', 200)]);
    }

    /** @param array<string, mixed> $args */
    private function integrity(array $args = []): PendingCommand
    {
        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:integrity', $args);

        return $command;
    }

    /** @param array<string, mixed> $attributes */
    private function restaurant(array $attributes): Restaurant
    {
        return Restaurant::factory()->create($attributes + ['is_active' => true, 'ai_metadata' => null]);
    }

    public function test_report_only_changes_nothing(): void
    {
        $r = $this->restaurant(['website_url' => 'https://www.merriam-webster.com/dictionary/all']);

        $this->integrity()->assertSuccessful()->expectsOutputToContain('Report only');

        $this->assertSame('https://www.merriam-webster.com/dictionary/all', $r->fresh()?->website_url);
        $this->assertSame(0, FieldQuarantine::query()->count());
    }

    public function test_blocked_website_is_quarantined_with_the_photo_and_socials_scraped_from_it(): void
    {
        $r = $this->restaurant([
            'website_url' => 'https://www.reddit.com/r/somewhere/top/',
            'photo_url' => 'https://www.reddit.com/og.png',
            'photo_source' => 'website',
        ]);
        $r->socialLinks()->create(['platform' => 'twitter', 'url' => 'https://twitter.com/reddit', 'verified_at' => now()]);

        $this->integrity(['--apply' => true, '--only' => ['website_blocked']])->assertSuccessful();

        $fresh = Restaurant::query()->whereKey($r->id)->firstOrFail();
        $this->assertNull($fresh->website_url);
        $this->assertNull($fresh->photo_url);
        $this->assertSame(0, $fresh->socialLinks()->count());
        $this->assertEqualsCanonicalizing(
            ['website_blocked_domain', 'photo_from_rejected_website', 'social_from_rejected_website'],
            FieldQuarantine::query()->where('restaurant_id', $r->id)->distinct()->pluck('reason')->all()
        );
    }

    public function test_social_profile_stored_as_website_becomes_a_social_link(): void
    {
        $r = $this->restaurant(['website_url' => 'https://www.facebook.com/AmayasTacoVillage/']);

        $this->integrity(['--apply' => true, '--only' => ['website_blocked']])->assertSuccessful();

        $this->assertNull($r->fresh()?->website_url);
        $this->assertSame('https://www.facebook.com/AmayasTacoVillage', $r->socialLinks()->value('url'));
        $this->assertSame(1, $r->fresh()?->social_links_count);
    }

    public function test_junk_social_links_are_quarantined_and_valid_ones_canonicalized(): void
    {
        $r = $this->restaurant([]);
        $r->socialLinks()->create(['platform' => 'facebook', 'url' => 'http://www.facebook.com/2008', 'verified_at' => now()]);
        $r->socialLinks()->create(['platform' => 'instagram', 'url' => 'http://instagram.com/amayas_tacos/', 'verified_at' => now()]);

        $this->integrity(['--apply' => true, '--only' => ['social_junk']])->assertSuccessful();

        $this->assertSame(['https://www.instagram.com/amayas_tacos'], $r->socialLinks()->pluck('url')->all());
        $this->assertSame('social_not_a_profile', FieldQuarantine::query()->value('reason'));
        $this->assertSame(1, $r->fresh()?->social_links_count);
    }

    public function test_shared_corporate_account_is_brand_scoped(): void
    {
        foreach (range(1, 3) as $i) {
            $this->restaurant(['name' => "Domino's", 'social_links_count' => 1])
                ->socialLinks()->create(['platform' => 'instagram', 'url' => 'https://www.instagram.com/dominos', 'verified_at' => now()]);
        }

        $this->integrity(['--apply' => true, '--only' => ['social_brand']])->assertSuccessful();

        $this->assertSame(3, RestaurantSocialLink::query()->where('scope', RestaurantSocialLink::SCOPE_BRAND)->count());
        $this->assertSame(0, (int) Restaurant::query()->sum('social_links_count'));
    }

    public function test_copied_rating_is_kept_only_on_the_owner_and_restorable(): void
    {
        // The Las Vegas venue's rating AND phone were copied onto same-named
        // rows elsewhere; the shared 702 (Nevada) number identifies the owner.
        $owner = $this->restaurant(['name' => 'Aloha Kitchen', 'city' => 'Las Vegas', 'state' => 'NV', 'phone' => '7028959444', 'google_rating' => 4.3, 'google_review_count' => 132]);
        $copy = $this->restaurant(['name' => 'Aloha Kitchen', 'city' => 'Phoenix', 'state' => 'AZ', 'phone' => '7028959444', 'google_rating' => 4.3, 'google_review_count' => 132]);
        $unrelated = $this->restaurant(['name' => 'Different Name', 'city' => 'Tulsa', 'state' => 'OK', 'google_rating' => 4.3, 'google_review_count' => 132]);

        $this->integrity(['--apply' => true, '--only' => ['copied_rating', 'copied_phone']])->assertSuccessful();

        $ownerAfter = Restaurant::query()->whereKey($owner->id)->firstOrFail();
        $copyAfter = Restaurant::query()->whereKey($copy->id)->firstOrFail();
        $this->assertSame(4.3, $ownerAfter->google_rating);
        $this->assertSame('7028959444', $ownerAfter->phone);
        $this->assertNull($copyAfter->google_rating);
        $this->assertSame(0, $copyAfter->google_review_count);
        $this->assertNull($copyAfter->phone);
        $this->assertSame(4.3, $unrelated->fresh()?->google_rating, 'a coincidental pair on a different name is not a copy');

        $this->integrity(['--restore' => 'rating_copied_across_cities'])->assertSuccessful();

        $restored = Restaurant::query()->whereKey($copy->id)->firstOrFail();
        $this->assertSame(4.3, $restored->google_rating);
        $this->assertSame(132, $restored->google_review_count);
    }

    public function test_address_in_another_state_is_quarantined_with_its_copied_phone(): void
    {
        $r = $this->restaurant(['name' => 'Panda Express', 'city' => 'Macon', 'state' => 'GA', 'address' => '4429 Rangeline Rd, Mobile, AL 36619', 'phone' => '2516610000']);
        $ok = $this->restaurant(['city' => 'Atlanta', 'state' => 'GA', 'address' => '2148 Johnson Ferry Rd NE, Atlanta, GA 30319']);

        $this->integrity(['--apply' => true, '--only' => ['address_other_state']])->assertSuccessful();

        $this->assertNull($r->fresh()?->address);
        $this->assertNull($r->fresh()?->phone);
        $this->assertSame('2148 Johnson Ferry Rd NE, Atlanta, GA 30319', $ok->fresh()?->address);
    }

    public function test_address_from_another_metro_is_quarantined_on_unmatched_rows(): void
    {
        $copied = 'Virginia Beach Boulevard, 4554, Virginia Beach, 23462';
        $tampa = ['name' => 'Bahama Breeze', 'city' => 'Tampa', 'state' => 'FL', 'latitude' => 27.970, 'longitude' => -82.563];
        $unmatched = $this->restaurant(array_merge($tampa, ['address' => $copied, 'postal_code' => '23462', 'overture_id' => null]));
        // Overture-matched: overture:import replaces it with the place's address.
        $matched = $this->restaurant(array_merge($tampa, ['address' => $copied, 'postal_code' => '23462', 'overture_id' => 'ov-1', 'latitude' => 27.971]));
        // Same metro (the airport location of an Austin diner): too close to
        // call without an Overture place.
        $nearby = $this->restaurant(['address' => 'North Lamar Boulevard, 600, Austin, 78703', 'postal_code' => null, 'overture_id' => null, 'latitude' => 30.2026, 'longitude' => -97.6641]);
        // Pinned on the configured Tampa city center: the pin may be the wrong part.
        $center = $this->restaurant(array_merge($tampa, ['address' => $copied, 'postal_code' => '23462', 'overture_id' => null, 'latitude' => 27.9506, 'longitude' => -82.4572]));

        $this->integrity(['--only' => ['address_far_from_location']])->assertSuccessful()
            ->expectsOutputToContain('address_far_from_location: 1 flagged (Overture-matched, left to overture:import 1)');
        $this->integrity(['--apply' => true, '--only' => ['address_far_from_location']])->assertSuccessful();

        $this->assertNull($unmatched->fresh()?->address);
        $this->assertNull($unmatched->fresh()?->postal_code);
        $this->assertSame($copied, $matched->fresh()?->address);
        $this->assertSame('North Lamar Boulevard, 600, Austin, 78703', $nearby->fresh()?->address);
        $this->assertSame($copied, $center->fresh()?->address);

        $this->integrity(['--restore' => 'address_far_from_location'])->assertSuccessful()->run();
        $restored = Restaurant::query()->whereKey($unmatched->id)->firstOrFail();
        $this->assertSame($copied, $restored->address);
        $this->assertSame('23462', $restored->postal_code);
    }

    public function test_address_in_another_state_is_kept_when_its_zip_is_at_the_pin(): void
    {
        // Farzi NYC: the address is right, the row's "Anchorage, AK" is wrong.
        $r = $this->restaurant(['city' => 'Anchorage', 'state' => 'AK', 'address' => '78 Leonard St, New York, NY 10013', 'latitude' => 40.7172, 'longitude' => -74.0053]);

        $this->integrity(['--apply' => true, '--only' => ['address_other_state']])->assertSuccessful();

        $this->assertSame('78 Leonard St, New York, NY 10013', $r->fresh()?->address);
    }

    public function test_city_far_from_location_is_corrected_from_the_evidence_and_restorable(): void
    {
        $at = fn (string $city, string $state, ?string $address, float $lat, float $lng, array $extra = []) => $this->restaurant(
            $extra + ['city' => $city, 'state' => $state, 'address' => $address, 'postal_code' => null, 'latitude' => $lat, 'longitude' => $lng]
        );
        // The search grid's city on a venue in the next town.
        $novi = $at('Ann Arbor', 'MI', '39777 Grand River Ave, Novi, MI 48375', 42.4806, -83.4755);
        // Copied by the old name-only backfill; the pin sits on the configured
        // New York center, which the address's ZIP vouches for.
        $farzi = $at('Anchorage', 'AK', '78 Leonard St, New York, NY 10013', 40.7172, -74.0053);
        $berkeley = $at('San Francisco', 'CA', 'Shattuck Avenue, 2429, Berkeley, 94704', 37.8651, -122.2675);
        // The ZIP at the pin settles the state when the address names no city.
        $kck = $at('Kansas City', 'MO', 'Rainbow Boulevard, 4316, 66103', 39.0512, -94.6107);
        $wny = $at('New York', 'NY', 'Bergenline Avenue, 5901, 07093', 40.7870, -74.0109);
        // No evidence at all, thousands of km off.
        $bronx = $at('Anchorage', 'AK', null, 40.8176, -73.9282);
        // address_other_state removed the right address for disagreeing with
        // the wrong city: it comes back as the evidence.
        $parsippany = $at('Charleston Wv', 'WV', null, 40.8579, -74.4260);
        FieldQuarantine::query()->create([
            'restaurant_id' => $parsippany->id, 'field' => 'address', 'old_value' => '321 US-46, Parsippany, NJ 07054',
            'reason' => 'address_other_state', 'detector' => 'restaurants:integrity', 'quarantined_at' => now(),
        ]);
        // An address built with the search's state: the ZIP is Maryland's.
        $silver = $at('Silver Spring', 'DC', '8311 Fenton Street, Silver Spring, DC 20910', 38.9937, -77.0269);
        $label = $at('Washington Dc', 'DC', '2400 18th St NW, Washington, DC 20009', 38.9170, -77.0405);
        // Left alone: a neighborhood its own address names; a village the
        // Census lists only across the river (the ZIP is Pennsylvania's); San
        // Francisco, whose Census point sits 55 km out on the Farallon Islands.
        $charlestown = $at('Charlestown', 'MA', '28 Austin St, Charlestown, MA 02129', 42.3782, -71.0602);
        $crossing = $at('Washington Crossing', 'PA', '1251 River Road', 40.3136, -74.8957, ['postal_code' => '18977']);
        $mission = $at('San Francisco', 'CA', '4000 18th St, San Francisco, CA 94114', 37.7609, -122.4351);

        $this->integrity(['--only' => ['city_far_from_location']])->assertSuccessful()->expectsOutputToContain(
            'city_far_from_location: 9 flagged (corrected from address 5, address restored 1, state corrected 1, state corrected, city removed 1, removed 1, address state fixed 1, grid labels renamed 1, left, no evidence 1)'
        );
        $this->assertSame('Ann Arbor', $novi->fresh()?->city, 'report only');

        $this->integrity(['--apply' => true, '--only' => ['city_far_from_location']])->assertSuccessful();

        $place = fn (Restaurant $r) => [$r->fresh()?->city, $r->fresh()?->state];
        $this->assertSame(['Novi', 'MI'], $place($novi));
        $this->assertSame(['New York', 'NY'], $place($farzi));
        $this->assertSame(['Berkeley', 'CA'], $place($berkeley));
        $this->assertSame(['Kansas City', 'KS'], $place($kck));
        $this->assertSame([null, 'NJ'], $place($wny));
        $this->assertSame([null, null], $place($bronx));
        $this->assertSame(['Parsippany', 'NJ'], $place($parsippany));
        $this->assertSame('321 US-46, Parsippany, NJ 07054', $parsippany->fresh()?->address);
        $this->assertSame(['Silver Spring', 'MD'], $place($silver));
        $this->assertSame('8311 Fenton Street, Silver Spring, MD 20910', $silver->fresh()?->address);
        $this->assertSame(['Washington', 'DC'], $place($label));
        $this->assertSame(['Charlestown', 'MA'], $place($charlestown));
        $this->assertSame(['Washington Crossing', 'PA'], $place($crossing));
        $this->assertSame(['San Francisco', 'CA'], $place($mission));

        $this->integrity(['--restore' => 'city_far_from_location'])->assertSuccessful()->run();
        $this->integrity(['--restore' => 'city_grid_label'])->assertSuccessful()->run();

        $this->assertSame(['Ann Arbor', 'MI'], $place($novi));
        $this->assertSame(['Anchorage', 'AK'], $place($farzi));
        $this->assertSame(['New York', 'NY'], $place($wny));
        $this->assertSame(['Anchorage', 'AK'], $place($bronx));
        $this->assertSame(['Silver Spring', 'DC'], $place($silver));
        $this->assertSame('8311 Fenton Street, Silver Spring, DC 20910', Restaurant::query()->whereKey($silver->id)->value('address'));
        $this->assertSame(['Washington Dc', 'DC'], $place($label));
    }

    public function test_an_address_naming_the_search_city_across_a_state_line_is_no_evidence(): void
    {
        $at = fn (string $address, string $zip, float $lat, float $lng) => $this->restaurant(
            ['city' => 'Washington Dc', 'state' => 'DC', 'address' => $address, 'postal_code' => $zip, 'latitude' => $lat, 'longitude' => $lng]
        );
        // National Harbor, MD and Annandale, VA venues whose source wrote the
        // search's city into the address. The ZIP settles the state; the city
        // is unknown, not "Washington, MD".
        $harbor = $at('151 American Way, Washington, DC 20745', '20745', 38.7842, -77.0156);
        $annandale = $at('7131 Little River Turnpike, Washington DC, DC 22003', '22003', 38.8288, -77.1917);
        // A town the Census doesn't list as a place: the ZIP vouches for it.
        $natick = $this->restaurant(['city' => 'Worcester', 'state' => 'MA', 'address' => '58 Main St, Natick, MA 01760', 'postal_code' => null, 'latitude' => 42.2835, 'longitude' => -71.3495]);

        $this->integrity(['--apply' => true, '--only' => ['city_far_from_location']])->assertSuccessful();

        $place = fn (Restaurant $r) => [$r->fresh()?->city, $r->fresh()?->state];
        $this->assertSame([null, 'MD'], $place($harbor));
        $this->assertSame([null, 'VA'], $place($annandale));
        $this->assertSame(['Natick', 'MA'], $place($natick));
    }

    public function test_ai_guesses_are_quarantined_but_verified_ai_websites_stay(): void
    {
        $guess = $this->restaurant(['price_range' => '$$', 'ai_metadata' => ['fields_updated' => ['price_range', 'description']]]);
        $verified = $this->restaurant([
            'website_url' => 'https://ok.example',
            'website_identity' => 'verified',
            'ai_metadata' => ['fields_updated' => ['website_url']],
        ]);

        $this->integrity(['--apply' => true, '--only' => ['ai_guess']])->assertSuccessful();

        $this->assertNull($guess->fresh()?->price_range);
        $this->assertSame('https://ok.example', $verified->fresh()?->website_url);
    }

    public function test_unknown_detector_fails(): void
    {
        $this->integrity(['--only' => ['nope']])->assertFailed();
    }
}
