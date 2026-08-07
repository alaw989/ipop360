<?php

namespace Tests\Unit;

use App\Services\AiEnrichmentService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit coverage for AiEnrichmentService's provider chain + response parsing.
 *
 * The service reads config at call time (buildProviderChain), so each test
 * sets config(['services.ai' => ...]) before invoking enrichRestaurant.
 */
class AiEnrichmentServiceTest extends TestCase
{
    private const PRIMARY_URL = 'https://api.groq.com/openai/v1/chat/completions';

    private const FALLBACK_URL = 'https://models.inference.ai.azure.com/chat/completions';

    private AiEnrichmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiEnrichmentService;
        Log::spy();
    }

    private function providerConfig(array $primary = [], ?array $fallback = null): array
    {
        return [
            'api_key' => $primary['api_key'] ?? 'pk-primary',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'fallback' => $fallback === null ? [] : [$fallback],
        ];
    }

    private function chatResponse(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    public function test_no_api_key_returns_null_noop(): void
    {
        config(['services.ai' => $this->providerConfig(['api_key' => ''])]);

        $this->assertNull($this->service->enrichRestaurant(['name' => 'Test']));

        Http::assertNothingSent();
    }

    public function test_success_returns_parsed_content_with_rating_fields_stripped(): void
    {
        config(['services.ai' => $this->providerConfig()]);

        Http::fake([
            self::PRIMARY_URL => Http::response($this->chatResponse(json_encode([
                'normalized_address' => '123 Main St',
                'phone' => '(415) 555-0100',
                'price_range' => '$$',
                'rating' => 4.9,
                'review_count' => 999,
                'score' => 87,
            ]))),
        ]);

        $result = $this->service->enrichRestaurant(['name' => 'Test']);

        $this->assertIsArray($result);
        $this->assertSame('123 Main St', $result['normalized_address']);
        $this->assertSame('$$', $result['price_range']);
        $this->assertArrayNotHasKey('rating', $result);
        $this->assertArrayNotHasKey('review_count', $result);
        $this->assertArrayNotHasKey('score', $result);
    }

    public function test_request_includes_restaurant_fields_in_prompt(): void
    {
        config(['services.ai' => $this->providerConfig()]);

        Http::fake([
            self::PRIMARY_URL => Http::response($this->chatResponse('{}')),
        ]);

        $this->service->enrichRestaurant([
            'name' => 'Taco Mesa Oaxaca',
            'city' => 'San Francisco',
            'website_url' => 'https://taco.example.com',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $userContent = $body['messages'][1]['content'] ?? '';

            return str_contains($userContent, 'Taco Mesa Oaxaca')
                && str_contains($userContent, 'San Francisco')
                && str_contains($userContent, 'https://taco.example.com');
        });
    }

    public function test_empty_content_returns_null(): void
    {
        config(['services.ai' => $this->providerConfig()]);

        Http::fake([
            self::PRIMARY_URL => Http::response($this->chatResponse('')),
        ]);

        $this->assertNull($this->service->enrichRestaurant(['name' => 'Test']));
    }

    public function test_invalid_json_content_returns_null(): void
    {
        config(['services.ai' => $this->providerConfig()]);

        Http::fake([
            self::PRIMARY_URL => Http::response($this->chatResponse('not json at all')),
        ]);

        $this->assertNull($this->service->enrichRestaurant(['name' => 'Test']));
    }

    public function test_rate_limited_primary_falls_back_to_second_provider(): void
    {
        config(['services.ai' => $this->providerConfig(
            [],
            ['api_key' => 'pk-fallback', 'base_url' => 'https://models.inference.ai.azure.com', 'model' => 'gpt-4o-mini'],
        )]);

        Http::fake([
            self::PRIMARY_URL => Http::response('', 429),
            self::FALLBACK_URL => Http::response($this->chatResponse(json_encode([
                'price_range' => '$',
                'cuisines' => ['Italian'],
            ]))),
        ]);

        $result = $this->service->enrichRestaurant(['name' => 'Test']);

        $this->assertIsArray($result);
        $this->assertSame('$', $result['price_range']);
        $this->assertSame(['Italian'], $result['cuisines']);
    }

    public function test_non_429_failure_returns_null_without_fallback(): void
    {
        config(['services.ai' => $this->providerConfig(
            [],
            ['api_key' => 'pk-fallback', 'base_url' => 'https://models.inference.ai.azure.com', 'model' => 'gpt-4o-mini'],
        )]);

        Http::fake([
            self::PRIMARY_URL => Http::response('', 500),
        ]);

        $this->assertNull($this->service->enrichRestaurant(['name' => 'Test']));

        Http::assertSentCount(1);
    }

    public function test_all_providers_rate_limited_throws_runtime_exception(): void
    {
        config(['services.ai' => $this->providerConfig(
            [],
            ['api_key' => 'pk-fallback', 'base_url' => 'https://models.inference.ai.azure.com', 'model' => 'gpt-4o-mini'],
        )]);

        Http::fake([
            self::PRIMARY_URL => Http::response('', 429),
            self::FALLBACK_URL => Http::response('', 429),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('All AI providers rate-limited or failed');

        $this->service->enrichRestaurant(['name' => 'Test']);
    }

    public function test_empty_fallback_api_key_is_skipped_and_all_exhausted_throws(): void
    {
        config(['services.ai' => $this->providerConfig(
            [],
            ['api_key' => '', 'base_url' => 'https://models.inference.ai.azure.com', 'model' => 'gpt-4o-mini'],
        )]);

        Http::fake([
            self::PRIMARY_URL => Http::response('', 429),
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->enrichRestaurant(['name' => 'Test']);

        Http::assertSentCount(1);
    }

    public function test_response_without_choices_content_returns_null(): void
    {
        config(['services.ai' => $this->providerConfig()]);

        Http::fake([
            self::PRIMARY_URL => Http::response(['choices' => [['message' => ['content' => null]]]]),
        ]);

        $this->assertNull($this->service->enrichRestaurant(['name' => 'Test']));
    }

    public function test_request_exception_from_transport_is_caught_and_falls_back(): void
    {
        config(['services.ai' => $this->providerConfig(
            [],
            ['api_key' => 'pk-fallback', 'base_url' => 'https://models.inference.ai.azure.com', 'model' => 'gpt-4o-mini'],
        )]);

        Http::fake([
            self::PRIMARY_URL => fn () => throw new RequestException(Http::response('', 429)),
            self::FALLBACK_URL => Http::response($this->chatResponse(json_encode([
                'normalized_address' => '42 Fallback St',
            ]))),
        ]);

        $result = $this->service->enrichRestaurant(['name' => 'Test']);

        $this->assertIsArray($result);
        $this->assertSame('42 Fallback St', $result['normalized_address']);
    }
}
