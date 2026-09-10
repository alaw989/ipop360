<?php

namespace Tests\Feature;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\FieldQuarantineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every integrity cleanup goes through FieldQuarantineService, so it must be
 * lossless: raw values (JSON columns included) round-trip exactly, a restore
 * never clobbers a newer value, and quarantined rows are remembered so the
 * backfill never re-saves them.
 */
class FieldQuarantineServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): FieldQuarantineService
    {
        return $this->app->make(FieldQuarantineService::class);
    }

    public function test_quarantines_and_restores_scalar_and_json_fields_exactly(): void
    {
        $hours = ['structured' => false, 'raw_text' => 'Mo-Su 11:00-21:00'];
        $restaurant = Restaurant::factory()->create([
            'website_url' => 'https://www.merriam-webster.com/dictionary/taqueria',
            'website_identity' => 'rejected',
            'opening_hours' => $hours,
            'phone' => '5551112222',
        ]);

        $count = $this->service()->quarantineFields($restaurant, ['website_url', 'opening_hours'], 'reference_page', 'test', ['note' => 'x']);

        $this->assertSame(2, $count);
        $fresh = Restaurant::query()->whereKey($restaurant->id)->firstOrFail();
        $this->assertNull($fresh->website_url);
        $this->assertNull($fresh->website_identity);
        $this->assertNull($fresh->opening_hours);
        $this->assertSame('5551112222', $fresh->phone, 'fields not listed are untouched');
        $this->assertNull($restaurant->website_url, 'the in-memory model reflects the cleared state');

        foreach (FieldQuarantine::all() as $entry) {
            $this->assertTrue($this->service()->restore($entry));
        }

        $restored = Restaurant::query()->whereKey($restaurant->id)->firstOrFail();
        $this->assertSame('https://www.merriam-webster.com/dictionary/taqueria', $restored->website_url);
        $this->assertSame($hours, $restored->opening_hours);
        $this->assertSame(2, FieldQuarantine::query()->whereNotNull('restored_at')->count());
    }

    public function test_empty_values_are_not_quarantined(): void
    {
        $restaurant = Restaurant::factory()->create(['website_url' => null, 'photos' => []]);

        $this->assertSame(0, $this->service()->quarantineFields($restaurant, ['website_url', 'photos'], 'r', 'test'));
        $this->assertSame(0, FieldQuarantine::query()->count());
    }

    public function test_rating_pair_clears_review_count_to_its_not_null_default(): void
    {
        $restaurant = Restaurant::factory()->create(['google_rating' => 4.7, 'google_review_count' => 10085]);

        $this->assertSame(2, $this->service()->quarantineRating($restaurant, 'copied_rating', 'test'));

        $fresh = Restaurant::query()->whereKey($restaurant->id)->firstOrFail();
        $this->assertNull($fresh->google_rating);
        $this->assertSame(0, $fresh->google_review_count);

        foreach (FieldQuarantine::all() as $entry) {
            $this->assertTrue($this->service()->restore($entry));
        }
        $restored = Restaurant::query()->whereKey($restaurant->id)->firstOrFail();
        $this->assertSame(4.7, $restored->google_rating);
        $this->assertSame(10085, $restored->google_review_count);
    }

    public function test_restore_never_overwrites_a_newer_value(): void
    {
        $restaurant = Restaurant::factory()->create(['website_url' => 'https://wrong.example']);
        $this->service()->quarantineFields($restaurant, ['website_url'], 'no_name_evidence', 'test');
        Restaurant::query()->whereKey($restaurant->id)->update(['website_url' => 'https://right.example']);

        $entry = FieldQuarantine::query()->firstOrFail();

        $this->assertFalse($this->service()->restore($entry));
        $this->assertSame('https://right.example', Restaurant::query()->whereKey($restaurant->id)->value('website_url'));
        $this->assertNull($entry->fresh()?->restored_at);
    }

    public function test_is_quarantined_until_restored(): void
    {
        $restaurant = Restaurant::factory()->create(['website_url' => 'https://wrong.example']);
        $this->service()->quarantineFields($restaurant, ['website_url'], 'no_name_evidence', 'test');

        $this->assertTrue($this->service()->isQuarantined($restaurant->id, 'website_url', 'https://wrong.example'));
        $this->assertFalse($this->service()->isQuarantined($restaurant->id, 'website_url', 'https://other.example'));

        $this->service()->restore(FieldQuarantine::query()->firstOrFail());

        $this->assertFalse($this->service()->isQuarantined($restaurant->id, 'website_url', 'https://wrong.example'));
    }

    public function test_social_link_quarantine_round_trips(): void
    {
        $restaurant = Restaurant::factory()->create();
        $link = $restaurant->socialLinks()->create([
            'platform' => 'facebook',
            'url' => 'http://www.facebook.com/2008',
            'verified_at' => now(),
        ]);

        $this->service()->quarantineSocialLink($link, 'not_a_profile', 'test');

        $this->assertSame(0, RestaurantSocialLink::query()->count());
        $entry = FieldQuarantine::query()->firstOrFail();
        $this->assertSame(FieldQuarantineService::SOCIAL_LINK_FIELD, $entry->field);

        $this->assertTrue($this->service()->restore($entry));
        $restored = RestaurantSocialLink::query()->firstOrFail();
        $this->assertSame('http://www.facebook.com/2008', $restored->url);
        $this->assertNotNull($restored->verified_at);
    }
}
