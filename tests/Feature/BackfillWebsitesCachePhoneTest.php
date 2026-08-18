<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\ExternalApiCache;
use App\Models\Restaurant;
use Database\Seeders\CuisineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract for the cache phase of restaurants:backfill-websites: cached
 * live-search venue data is used not just to backfill missing website URLs but
 * also to backfill missing phone numbers, price ranges, descriptions, ratings
 * and more (fill-empty only). The phone (46%) / price (75%) / description (83%)
 * / rating (95%) gaps are served for free from the existing cache, keeping the
 * quota-bound AI-enrich budget for rows the cache cannot help.
 */
class BackfillWebsitesCachePhoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CuisineSeeder::class);
    }

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

    /** @return array<int, string> */
    private function cuisineSlugs(int $restaurantId): array
    {
        return Restaurant::query()->whereKey($restaurantId)->firstOrFail()->cuisines
            ->pluck('slug')->sort()->values()->all();
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

    public function test_backfills_address_from_cached_venue_for_name_match(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Addressed Eatery',
                'website' => 'https://addressedeatery.example',
                'address' => '123 Main St, Denver, CO 80202',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Addressed Eatery',
            'website_url' => 'https://addressedeatery.example',
            'phone' => '5550007777',
            'address' => null,
            'menu_url' => 'https://addressedeatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Addressed Eatery')->firstOrFail();
        $this->assertSame('123 Main St, Denver, CO 80202', $restaurant->address);
    }

    public function test_does_not_overwrite_existing_address(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Keeps Address',
                'website' => 'https://keepsaddress.example',
                'address' => 'Cached Address Ln, Nowhere',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Keeps Address',
            'website_url' => 'https://keepsaddress.example',
            'phone' => '5550008888',
            'address' => 'Our Own Verified St, Denver, CO 80202',
            'menu_url' => 'https://keepsaddress.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Keeps Address')->firstOrFail();
        $this->assertSame('Our Own Verified St, Denver, CO 80202', $restaurant->address);
    }

    public function test_short_or_missing_address_is_not_stored(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Tiny Address',
                'website' => 'https://tinyaddress.example',
                'address' => 'x',
            ],
            [
                'title' => 'No Address',
                'website' => 'https://noaddress.example',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Tiny Address',
            'website_url' => 'https://tinyaddress.example',
            'phone' => '5550009999',
            'address' => null,
            'menu_url' => 'https://tinyaddress.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'No Address',
            'website_url' => 'https://noaddress.example',
            'phone' => '5550001110',
            'address' => null,
            'menu_url' => 'https://noaddress.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertNull(Restaurant::where('name', 'Tiny Address')->firstOrFail()->address);
        $this->assertNull(Restaurant::where('name', 'No Address')->firstOrFail()->address);
    }

    public function test_backfills_address_when_website_missing(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Website-less Address',
                'address' => '456 Oak Ave, Austin, TX 78701',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Website-less Address',
            'website_url' => null,
            'phone' => '5550002220',
            'address' => null,
            'menu_url' => 'https://websitelessaddress.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Website-less Address')->firstOrFail();
        $this->assertSame('456 Oak Ave, Austin, TX 78701', $restaurant->address);
    }

    public function test_backfills_opening_hours_from_cached_venue_for_name_match(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Hours Eatery',
                'website' => 'https://hourseatery.example',
                'opening_hours' => 'Mo-Su 11:00-21:00',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Hours Eatery',
            'website_url' => 'https://hourseatery.example',
            'phone' => '5550003330',
            'address' => '123 Hours St, Denver, CO',
            'opening_hours' => null,
            'menu_url' => 'https://hourseatery.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Hours Eatery')->firstOrFail();
        $this->assertSame(
            ['structured' => false, 'raw_text' => 'Mo-Su 11:00-21:00'],
            $restaurant->opening_hours
        );
    }

    public function test_does_not_overwrite_existing_opening_hours(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Keeps Hours',
                'website' => 'https://keepshours.example',
                'opening_hours' => 'Mo-Fr 09:00-17:00',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Keeps Hours',
            'website_url' => 'https://keepshours.example',
            'phone' => '5550003331',
            'address' => '123 Keeps St, Denver, CO',
            'opening_hours' => ['structured' => false, 'raw_text' => 'Sa-Su 10:00-18:00'],
            'menu_url' => 'https://keepshours.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Keeps Hours')->firstOrFail();
        $this->assertSame(
            ['structured' => false, 'raw_text' => 'Sa-Su 10:00-18:00'],
            $restaurant->opening_hours
        );
    }

    public function test_non_osm_or_junk_hours_are_not_stored(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Junk Hours',
                'website' => 'https://junkhours.example',
                'opening_hours' => 'closed',
            ],
            [
                'title' => 'No Hours',
                'website' => 'https://nohourseatery.example',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Junk Hours',
            'website_url' => 'https://junkhours.example',
            'phone' => '5550003332',
            'address' => '123 Junk St, Denver, CO',
            'opening_hours' => null,
            'menu_url' => 'https://junkhours.example/menu',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'No Hours',
            'website_url' => 'https://nohourseatery.example',
            'phone' => '5550003333',
            'address' => '123 No Hours St, Denver, CO',
            'opening_hours' => null,
            'menu_url' => 'https://nohourseatery.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertNull(Restaurant::where('name', 'Junk Hours')->firstOrFail()->opening_hours);
        $this->assertNull(Restaurant::where('name', 'No Hours')->firstOrFail()->opening_hours);
    }

    public function test_backfills_structured_opening_hours_from_serpapi_operating_hours_map(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Structured Hours Eatery',
                'website' => 'https://structuredhours.example',
                'operating_hours' => [
                    'monday' => '11 AM–9 PM',
                    'tuesday' => '11 AM–9 PM',
                    'wednesday' => '11 AM–1 PM, 3–8 PM',
                    'thursday' => 'Closed',
                    'friday' => '11 AM–10 PM',
                    'saturday' => '12 PM–1 AM',
                    'sunday' => '11 AM–9 PM',
                ],
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Structured Hours Eatery',
            'website_url' => 'https://structuredhours.example',
            'phone' => '5550004000',
            'address' => '123 Structured St, Denver, CO',
            'opening_hours' => null,
            'menu_url' => 'https://structuredhours.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Structured Hours Eatery')->firstOrFail();
        $this->assertSame(
            [
                'structured' => true,
                'hours' => [
                    ['day' => 'Monday', 'open' => '11 AM', 'close' => '9 PM'],
                    ['day' => 'Tuesday', 'open' => '11 AM', 'close' => '9 PM'],
                    ['day' => 'Friday', 'open' => '11 AM', 'close' => '10 PM'],
                    ['day' => 'Saturday', 'open' => '12 PM', 'close' => '1 AM'],
                    ['day' => 'Sunday', 'open' => '11 AM', 'close' => '9 PM'],
                ],
            ],
            $restaurant->opening_hours
        );
    }

    public function test_serpapi_operating_hours_do_not_overwrite_existing_opening_hours(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Keeps Structured Hours',
                'website' => 'https://keepsstructured.example',
                'operating_hours' => [
                    'monday' => '11 AM–9 PM',
                    'sunday' => '11 AM–9 PM',
                ],
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Keeps Structured Hours',
            'website_url' => 'https://keepsstructured.example',
            'phone' => '5550004001',
            'address' => '123 Keeps St, Denver, CO',
            'opening_hours' => ['structured' => false, 'raw_text' => 'Mo-Su 10:00-22:00'],
            'menu_url' => 'https://keepsstructured.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Keeps Structured Hours')->firstOrFail();
        $this->assertSame(
            ['structured' => false, 'raw_text' => 'Mo-Su 10:00-22:00'],
            $restaurant->opening_hours
        );
    }

    public function test_serpapi_operating_hours_map_with_no_usable_days_is_not_stored(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Closed Eatery',
                'website' => 'https://closedeatery.example',
                'operating_hours' => [
                    'monday' => 'Closed',
                    'tuesday' => 'Closed',
                    'wednesday' => 'Closed',
                    'thursday' => 'Closed',
                    'friday' => 'Closed',
                    'saturday' => 'Closed',
                    'sunday' => 'Closed',
                ],
            ],
            [
                'title' => 'Junk Map Eatery',
                'website' => 'https://junkmapeatery.example',
                'operating_hours' => [
                    'monday' => 'whatever',
                    'tuesday' => '24 hours',
                ],
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Closed Eatery',
            'website_url' => 'https://closedeatery.example',
            'phone' => '5550004002',
            'address' => '123 Closed St, Denver, CO',
            'opening_hours' => null,
            'menu_url' => 'https://closedeatery.example/menu',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Junk Map Eatery',
            'website_url' => 'https://junkmapeatery.example',
            'phone' => '5550004003',
            'address' => '123 Junk St, Denver, CO',
            'opening_hours' => null,
            'menu_url' => 'https://junkmapeatery.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertNull(Restaurant::where('name', 'Closed Eatery')->firstOrFail()->opening_hours);
        $this->assertNull(Restaurant::where('name', 'Junk Map Eatery')->firstOrFail()->opening_hours);
    }

    public function test_handles_single_object_cache_entry_shaped_as_one_venue(): void
    {
        // The preview source persists ONE restaurant object per cache row
        // (not a list), so the cache phase must not iterate its scalar fields
        // as if they were venues (which crashed parseExtractedPrice with an
        // int and aborted the whole command).
        ExternalApiCache::create([
            'source' => 'preview',
            'external_id' => 'test-single-object-'.uniqid(),
            'data' => [
                'id' => 6672,
                'name' => 'Single Object Eatery',
                'website_url' => 'https://singleobjecteatery.example',
                'phone' => '(555) 123-9999',
                'opening_hours' => 'Mo-Su 11:00-21:00',
                'address' => '350 West Chestnut St, Louisville, 40202',
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        Restaurant::factory()->create([
            'name' => 'Single Object Eatery',
            'website_url' => null,
            'phone' => null,
            'address' => null,
            'opening_hours' => null,
            'menu_url' => 'https://singleobjecteatery.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Single Object Eatery')->firstOrFail();
        $this->assertSame('https://singleobjecteatery.example', $restaurant->website_url);
        $this->assertSame('5551239999', $restaurant->phone);
        $this->assertSame('350 West Chestnut St, Louisville, 40202', $restaurant->address);
        $this->assertSame(
            ['structured' => false, 'raw_text' => 'Mo-Su 11:00-21:00'],
            $restaurant->opening_hours
        );
    }

    public function test_backfills_opening_hours_when_website_missing(): void
    {
        $this->seedCache('bizdata', [
            [
                'title' => 'Website-less Hours',
                'opening_hours' => 'Mo-Su 08:00-22:00',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Website-less Hours',
            'website_url' => null,
            'phone' => '5550003334',
            'address' => '123 Hoursless St, Denver, CO',
            'opening_hours' => null,
            'menu_url' => 'https://websitelesshours.example/menu',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Website-less Hours')->firstOrFail();
        $this->assertSame(
            ['structured' => false, 'raw_text' => 'Mo-Su 08:00-22:00'],
            $restaurant->opening_hours
        );
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

    public function test_backfills_photo_from_cached_serpapi_thumbnail_for_name_match(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Photogenic Eatery',
                'website' => 'https://photogeniceatery.example',
                'thumbnail' => 'https://lh3.googleusercontent.com/gps-cs-s/abc123=w1000-h1000-c-n',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Photogenic Eatery',
            'website_url' => 'https://photogeniceatery.example',
            'phone' => '5550006660',
            'photo_url' => null,
            'menu_url' => 'https://photogeniceatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Photogenic Eatery')->firstOrFail();
        $this->assertSame('https://lh3.googleusercontent.com/gps-cs-s/abc123=w1000-h1000-c-n', $restaurant->photo_url);
    }

    public function test_does_not_overwrite_existing_photo(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Keeps Photo',
                'website' => 'https://keepsphoto.example',
                'thumbnail' => 'https://lh3.googleusercontent.com/gps-cs-s/cached',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Keeps Photo',
            'website_url' => 'https://keepsphoto.example',
            'phone' => '5550006661',
            'photo_url' => 'https://keepsphoto.example/og.jpg',
            'menu_url' => 'https://keepsphoto.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Keeps Photo')->firstOrFail();
        $this->assertSame('https://keepsphoto.example/og.jpg', $restaurant->photo_url);
    }

    public function test_missing_or_invalid_thumbnail_is_not_stored(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'No Thumb',
                'website' => 'https://nothumb.example',
            ],
            [
                'title' => 'Bad Thumb',
                'website' => 'https://badthumb.example',
                'thumbnail' => 'not-a-url',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'No Thumb',
            'website_url' => 'https://nothumb.example',
            'phone' => '5550006662',
            'photo_url' => null,
            'menu_url' => 'https://nothumb.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Bad Thumb',
            'website_url' => 'https://badthumb.example',
            'phone' => '5550006663',
            'photo_url' => null,
            'menu_url' => 'https://badthumb.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertNull(Restaurant::where('name', 'No Thumb')->firstOrFail()->photo_url);
        $this->assertNull(Restaurant::where('name', 'Bad Thumb')->firstOrFail()->photo_url);
    }

    public function test_backfills_photo_when_website_missing(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Website-less Photo',
                'thumbnail' => 'https://lh3.googleusercontent.com/gps-cs-s/website-less',
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Website-less Photo',
            'website_url' => null,
            'phone' => '5550006664',
            'photo_url' => null,
            'menu_url' => 'https://websitelessphoto.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Website-less Photo')->firstOrFail();
        $this->assertSame('https://lh3.googleusercontent.com/gps-cs-s/website-less', $restaurant->photo_url);
    }

    public function test_backfills_google_rating_from_cached_serpapi_venue_for_name_match(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Rated Eatery',
                'website' => 'https://ratedeatery.example',
                'rating' => 4.5,
                'reviews' => 523,
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Rated Eatery',
            'website_url' => 'https://ratedeatery.example',
            'phone' => '5550007770',
            'google_rating' => null,
            'google_review_count' => 0,
            'menu_url' => 'https://ratedeatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Rated Eatery')->firstOrFail();
        $this->assertSame(4.5, $restaurant->google_rating);
        $this->assertSame(523, $restaurant->google_review_count);
    }

    public function test_does_not_overwrite_existing_google_rating(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Keeps Rating',
                'website' => 'https://keepsrating.example',
                'rating' => 2.5,
                'reviews' => 10,
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Keeps Rating',
            'website_url' => 'https://keepsrating.example',
            'phone' => '5550007771',
            'google_rating' => 4.8,
            'google_review_count' => 1200,
            'menu_url' => 'https://keepsrating.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Keeps Rating')->firstOrFail();
        $this->assertSame(4.8, $restaurant->google_rating);
        $this->assertSame(1200, $restaurant->google_review_count);
    }

    public function test_invalid_or_out_of_range_rating_is_not_stored(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'No Rating',
                'website' => 'https://norating.example',
            ],
            [
                'title' => 'Bad Rating',
                'website' => 'https://badrating.example',
                'rating' => 'not-a-number',
            ],
            [
                'title' => 'Out Of Range Rating',
                'website' => 'https://outofrange.example',
                'rating' => 9.0,
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'No Rating',
            'website_url' => 'https://norating.example',
            'phone' => '5550007772',
            'google_rating' => null,
            'google_review_count' => 0,
            'menu_url' => 'https://norating.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Bad Rating',
            'website_url' => 'https://badrating.example',
            'phone' => '5550007773',
            'google_rating' => null,
            'google_review_count' => 0,
            'menu_url' => 'https://badrating.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);
        Restaurant::factory()->create([
            'name' => 'Out Of Range Rating',
            'website_url' => 'https://outofrange.example',
            'phone' => '5550007774',
            'google_rating' => null,
            'google_review_count' => 0,
            'menu_url' => 'https://outofrange.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertNull(Restaurant::where('name', 'No Rating')->firstOrFail()->google_rating);
        $this->assertNull(Restaurant::where('name', 'Bad Rating')->firstOrFail()->google_rating);
        $this->assertNull(Restaurant::where('name', 'Out Of Range Rating')->firstOrFail()->google_rating);
    }

    public function test_backfills_google_rating_when_website_missing(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Website-less Rating',
                'rating' => 4.2,
                'reviews' => 88,
            ],
        ]);

        Restaurant::factory()->create([
            'name' => 'Website-less Rating',
            'website_url' => null,
            'phone' => '5550007775',
            'google_rating' => null,
            'google_review_count' => 0,
            'menu_url' => 'https://websitelessrating.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $restaurant = Restaurant::where('name', 'Website-less Rating')->firstOrFail();
        $this->assertSame(4.2, $restaurant->google_rating);
        $this->assertSame(88, $restaurant->google_review_count);
    }

    public function test_backfills_cuisines_from_cached_serpapi_types_for_name_match(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Cuisine Eatery',
                'website' => 'https://cuisineeatery.example',
                'types' => ['Chinese restaurant', 'Noodle house'],
            ],
        ]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Cuisine Eatery',
            'website_url' => 'https://cuisineeatery.example',
            'phone' => '5550008000',
            'menu_url' => 'https://cuisineeatery.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertSame(['chinese'], $this->cuisineSlugs($restaurant->id));
    }

    public function test_backfills_cuisines_from_singular_type_string(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Singular Type Eatery',
                'website' => 'https://singulartype.example',
                'type' => 'Thai restaurant',
            ],
        ]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Singular Type Eatery',
            'website_url' => 'https://singulartype.example',
            'phone' => '5550008001',
            'menu_url' => 'https://singulartype.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertSame(['thai'], $this->cuisineSlugs($restaurant->id));
    }

    public function test_does_not_add_cuisines_when_restaurant_already_tagged(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Already Tagged Eatery',
                'website' => 'https://alreadytagged.example',
                'types' => ['Italian restaurant'],
            ],
        ]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Already Tagged Eatery',
            'website_url' => 'https://alreadytagged.example',
            'phone' => '5550008002',
            'menu_url' => 'https://alreadytagged.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);
        $restaurant->cuisines()->attach(Cuisine::where('slug', 'mexican')->firstOrFail()->id);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertSame(['mexican'], $this->cuisineSlugs($restaurant->id));
    }

    public function test_ignores_types_that_do_not_normalize_to_a_seeded_cuisine(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'No Cuisine Type Eatery',
                'website' => 'https://nocuisinetype.example',
                'types' => ['Bar', 'Caterer', 'Pizza restaurant'],
            ],
        ]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'No Cuisine Type Eatery',
            'website_url' => 'https://nocuisinetype.example',
            'phone' => '5550008003',
            'menu_url' => 'https://nocuisinetype.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertSame([], $this->cuisineSlugs($restaurant->id));
    }

    public function test_backfills_cuisines_when_website_missing_via_phone_match(): void
    {
        $this->seedCache('serpapi', [
            [
                'title' => 'Some Other Display Name',
                'phone' => '(555) 123-4567',
                'types' => ['Japanese restaurant', 'Ramen restaurant'],
            ],
        ]);

        $restaurant = Restaurant::factory()->create([
            'name' => 'Phone Matched Cuisine',
            'website_url' => null,
            'phone' => '5551234567',
            'menu_url' => 'https://phonematched.example/menu',
            'opening_hours' => 'Mo-Su 11:00-21:00',
            'social_links_count' => 1,
        ]);

        $this->artisan('restaurants:backfill-websites', ['--skip-search' => true]);

        $this->assertSame(['japanese'], $this->cuisineSlugs($restaurant->id));
    }
}
