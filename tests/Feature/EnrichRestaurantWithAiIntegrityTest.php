<?php

namespace Tests\Feature;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\CuisineTagMapper;
use App\Services\WebsiteIdentityVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * The AI model has no browsing: anything it returns for a missing field is
 * recalled or invented. Pin what the job may store as fact.
 */
class EnrichRestaurantWithAiIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('restaurant-finder.website_scraper.ssrf_guard', false);
    }

    /** @param array<string, mixed> $result */
    private function runJob(Restaurant $restaurant, array $result): Restaurant
    {
        /** @var AiEnrichmentService&Mockery\MockInterface $ai */
        $ai = Mockery::mock(AiEnrichmentService::class);
        $ai->shouldReceive('enrichRestaurant')->once()->andReturn($result);

        (new EnrichRestaurantWithAi($restaurant->id))->handle($ai, app(CuisineTagMapper::class));

        return Restaurant::query()->whereKey($restaurant->id)->firstOrFail();
    }

    public function test_never_overwrites_an_existing_address(): void
    {
        $restaurant = Restaurant::factory()->create(['address' => '410 Harbor Way']);

        $fresh = $this->runJob($restaurant, ['normalized_address' => '410 Harbour Way, Suite 9']);

        $this->assertSame('410 Harbor Way', $fresh->address);
        $this->assertNotContains('address', $fresh->ai_metadata['fields_updated'] ?? []);
    }

    public function test_fills_an_empty_address(): void
    {
        $restaurant = Restaurant::factory()->create(['address' => null]);

        $fresh = $this->runJob($restaurant, ['normalized_address' => '410 Harbor Way']);

        $this->assertSame('410 Harbor Way', $fresh->address);
    }

    public function test_phone_and_price_are_kept_as_inference_only(): void
    {
        $restaurant = Restaurant::factory()->create(['phone' => null, 'price_range' => null]);

        $fresh = $this->runJob($restaurant, ['phone' => '(253) 555-0142', 'price_range' => '$$']);

        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->price_range);
        $this->assertSame(['phone' => '(253) 555-0142', 'price_range' => '$$'], $fresh->ai_metadata['inferred'] ?? null);
    }

    public function test_provenance_accumulates_across_runs(): void
    {
        $restaurant = Restaurant::factory()->create([
            'price_range' => '$$',
            'description' => null,
            'ai_metadata' => ['enriched_at' => now()->subDays(9)->toISOString(), 'fields_updated' => ['price_range'], 'inferred' => ['phone' => '555']],
        ]);

        $fresh = $this->runJob($restaurant, ['description' => 'Neighborhood noodle bar.']);

        $this->assertEqualsCanonicalizing(['price_range', 'description'], $fresh->ai_metadata['fields_updated'] ?? []);
        $this->assertSame(['phone' => '555'], $fresh->ai_metadata['inferred'] ?? null);
    }

    public function test_suggested_website_is_saved_only_when_verified(): void
    {
        Http::fake([
            'https://blueheron.example/' => Http::response('<html><head><title>Blue Heron Bistro</title></head><body>Call (253) 555-0142</body></html>'),
            '*' => Http::response('', 404),
        ]);
        $restaurant = Restaurant::factory()->create([
            'name' => 'Blue Heron Bistro',
            'phone' => '2535550142',
            'website_url' => null,
        ]);

        $fresh = $this->runJob($restaurant, ['website_url' => 'https://blueheron.example/']);

        $this->assertSame('https://blueheron.example/', $fresh->website_url);
        $this->assertSame(WebsiteIdentityVerifier::VERIFIED, $fresh->website_identity);
    }

    public function test_unverifiable_suggested_website_is_not_saved(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $restaurant = Restaurant::factory()->create(['website_url' => null]);

        $fresh = $this->runJob($restaurant, ['website_url' => 'https://www.merriam-webster.com/dictionary/bistro']);

        $this->assertNull($fresh->website_url);
        $this->assertSame('https://www.merriam-webster.com/dictionary/bistro', $fresh->ai_metadata['inferred']['website_url'] ?? null);
    }
}
