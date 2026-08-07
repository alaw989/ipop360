<?php

namespace Tests\Unit;

use App\Services\AiEnrichmentService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiEnrichmentServiceTest extends TestCase
{
    private AiEnrichmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiEnrichmentService;

        // Give the service a deterministic provider chain for tests that need a key.
        Config::set('services.ai.api_key', 'test-primary-key');
        Config::set('services.ai.base_url', 'https://api.groq.com/openai/v1');
        Config::set('services.ai.model', 'llama-3.3-70b-versatile');
        Config::set('services.ai.fallback', [
            [
                'api_key' => 'test-fallback-key',
                'base_url' => 'https://models.inference.ai.azure.com',
                'model' => 'gpt-4o-mini',
            ],
        ]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Config::set('services.ai.api_key', null);
        parent::tearDown();
    }

    private function restaurantData(array $overrides = []): array
    {
        return array_merge([
            'id' => 42,
            'name' => 'Taste of Addis',
            'address' => '123 Main St',
            'city' => 'Addisville',
            'state' => 'OR',
            'postal_code' => '97201',
        ], $overrides);
    }

    private function openAiResponse(string $content, int $status = 200)
    {
        return Http::response(
            json_encode(['choices' => [['message' => ['content' => $content]]]]),
            $status,
        );
    }

    public function test_no_op_when_no_api_key_configured(): void
    {
        Config::set('services.ai.api_key', null);

        $result = $this->service->enrichRestaurant($this->restaurantData());

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_returns_normalized_fields_from_successful_response(): void
    {
        Http::fake([
            'api.groq.com/*' => $this->openAiResponse(
                json_encode([
                    'normalized_address' => '123 Main Street, Addisville, OR 97201',
                    'phone' => '+15035551234',
                    'price_range' => '$$',
                    'cuisines' => ['Ethiopian', 'African'],
                ])
            ),
        ]);

        $result = $this->service->enrichRestaurant($this->restaurantData());

        $this->assertSame('123 Main Street, Addisville, OR 97201', $result['normalized_address']);
        $this->assertSame('$$', $result['price_range']);
        $this->assertSame(['Ethiopian', 'African'], $result['cuisines']);
    }

    public function test_strips_rating_fields_never_introduces_ratings(): void
    {
        // A buggy/off-prompt provider returning a rating must be scrubbed so the
        // AI can never mint a score from nothing (spec: never produce ratings).
        Http::fake([
            'api.groq.com/*' => $this->openAiResponse(
                json_encode([
                    'description' => 'Authentic Ethiopian cuisine',
                    'rating' => 5,
                    'review_count' => 999,
                    'score' => 0.95,
                ])
            ),
        ]);

        $result = $this->service->enrichRestaurant($this->restaurantData());

        $this->assertSame('Authentic Ethiopian cuisine', $result['description']);
        $this->assertArrayNotHasKey('rating', $result);
        $this->assertArrayNotHasKey('review_count', $result);
        $this->assertArrayNotHasKey('score', $result);
    }

    public function test_returns_null_when_provider_returns_invalid_json(): void
    {
        Http::fake([
            'api.groq.com/*' => $this->openAiResponse('this is not json'),
        ]);

        $result = $this->service->enrichRestaurant($this->restaurantData());

        $this->assertNull($result);
    }

    public function test_returns_null_on_non_rate_limit_failure_without_fallback(): void
    {
        // A 500 is non-retryable: no fallback, no attempt at the secondary.
        Http::fake([
            'api.groq.com/*' => $this->openAiResponse('{"choices":[{"message":{"content":"{}"}}]}', 500),
        ]);

        $this->assertNull($this->service->enrichRestaurant($this->restaurantData()));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'models.inference.ai.azure.com'));
    }

    public function test_falls_back_to_fallback_provider_when_primary_rate_limited(): void
    {
        // Primary 429s → fallback succeeds and its payload wins.
        Http::fake([
            'api.groq.com/*' => $this->openAiResponse('{"choices":[{"message":{"content":"{}"}}]}', 429),
            'models.inference.ai.azure.com/*' => $this->openAiResponse(
                json_encode(['description' => 'From the fallback provider', 'price_range' => '$$$'])
            ),
        ]);

        $result = $this->service->enrichRestaurant($this->restaurantData());

        $this->assertSame('From the fallback provider', $result['description']);
        $this->assertSame('$$$', $result['price_range']);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'models.inference.ai.azure.com'));
    }

    public function test_prompt_request_is_sent_to_chat_completions(): void
    {
        Http::fake([
            'api.groq.com/*' => $this->openAiResponse('{}'),
        ]);

        $this->service->enrichRestaurant($this->restaurantData());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/chat/completions'));
    }
}
