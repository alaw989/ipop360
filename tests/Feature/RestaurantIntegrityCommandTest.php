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
