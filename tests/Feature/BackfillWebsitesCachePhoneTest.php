<?php

namespace Tests\Feature;

use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract for the cache phase of restaurants:backfill-websites: cached
 * live-search venue data is used not just to backfill missing website URLs but
 * also to backfill missing phone numbers, price ranges and descriptions
 * (fill-empty only). The phone (46%) / price (75%) / description (83%) gaps
 * are served for free from the existing cache, keeping the quota-bound AI-enrich
 * budget for rows the cache cannot help.
 */
class BackfillWebsitesCachePhoneTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $venues
     */
    private function seedCache(string $source, array $venues): void
    {
        ExternalApiCache::create([
            'source' => $source,
            'external_id' => 'test-'.uniqid(),
            'data' => $venues,
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_backfills_phone_from_cached_venue_for_name_match(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Phone Eatery',
                'website' => 'https://phoneeatery.example',
                'phone' => '(555) 123-4567',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Phone Eatery',
            'website_url' => 'https://phoneeatery.example',
            'phone' => null,
            'menu_url' => 'https://phoneeatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Phone Eatery')->firstOrFail();
        $this->assertSame('5551234567', $restaurant->phone);
    }

    public function test_does_not_overwrite_existing_phone(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Keeps Phone',
                'website' => 'https://keepsphone.example',
                'phone' => '(555) 111-2222',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Keeps Phone',
            'website_url' => 'https://keepsphone.example',
            'phone' => '5559998888',
            'menu_url' => 'https://keepsphone.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Keeps Phone')->firstOrFail();
        $this->assertSame('5559998888', $restaurant->phone);
    }

    public function test_backfills_phone_and_website_when_both_missing(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Bare Eatery',
                'website' => 'https://bareeatery.example',
                'phone' => '5553334444',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Bare Eatery',
            'website_url' => null,
            'phone' => null,
            'menu_url' => 'https://bareeatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Bare Eatery')->firstOrFail();
        $this->assertSame('https://bareeatery.example', $restaurant->website_url);
        $this->assertSame('5553334444', $restaurant->phone);
    }

    public function test_short_or_missing_phone_is_not_stored(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'No Phone',
                'website' => 'https://nophone.example',
            ],
            [
                'title' => 'Short Phone',
                'website' => 'https://shortphone.example',
                'phone' => '123',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'No Phone',
            'website_url' => 'https://nophone.example',
            'phone' => null,
            'menu_url' => 'https://nophone.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Short Phone',
            'website_url' => 'https://shortphone.example',
            'phone' => null,
            'menu_url' => 'https://shortphone.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertNull(Restaurant::where('name', 'No Phone')->firstOrFail()->phone);
        $this->assertNull(Restaurant::where('name', 'Short Phone')->firstOrFail()->phone);
    }

    public function test_backfills_description_from_cached_venue_for_name_match(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Described Eatery',
                'website' => 'https://describedeatery.example',
                'description' => 'Family-run Italian kitchen serving handmade pasta and wood-fired pizza since 1998.',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Described Eatery',
            'website_url' => 'https://describedeatery.example',
            'phone' => '5550001111',
            'description' => null,
            'menu_url' => 'https://describedeatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Described Eatery')->firstOrFail();
        $this->assertSame('Family-run Italian kitchen serving handmade pasta and wood-fired pizza since 1998.', $restaurant->description);
    }

    public function test_does_not_overwrite_existing_description(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Keeps Description',
                'website' => 'https://keepsdescription.example',
                'description' => 'A cached blurb that must not win.',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Keeps Description',
            'website_url' => 'https://keepsdescription.example',
            'phone' => '5550002222',
            'description' => 'Our own verified description.',
            'menu_url' => 'https://keepsdescription.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Keeps Description')->firstOrFail();
        $this->assertSame('Our own verified description.', $restaurant->description);
    }

    public function test_short_or_missing_description_is_not_stored(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Tiny Description',
                'website' => 'https://tinydescription.example',
                'description' => 'ok',
            ],
            [
                'title' => 'No Description',
                'website' => 'https://nodescription.example',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Tiny Description',
            'website_url' => 'https://tinydescription.example',
            'phone' => '5550003333',
            'description' => null,
            'menu_url' => 'https://tinydescription.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'No Description',
            'website_url' => 'https://nodescription.example',
            'phone' => '5550004444',
            'description' => null,
            'menu_url' => 'https://nodescription.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertNull(Restaurant::where('name', 'Tiny Description')->firstOrFail()->description);
        $this->assertNull(Restaurant::where('name', 'No Description')->firstOrFail()->description);
    }

    public function test_backfills_price_range_from_cached_venue_for_name_match(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Priced Eatery',
                'website' => 'https://pricedeatery.example',
                'extracted_price' => 25,
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Priced Eatery',
            'website_url' => 'https://pricedeatery.example',
            'phone' => '5550005555',
            'price_range' => null,
            'menu_url' => 'https://pricedeatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Priced Eatery')->firstOrFail();
        $this->assertSame('$$', $restaurant->price_range);
    }
}
