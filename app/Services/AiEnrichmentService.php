<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI enrichment service using OpenAI-compatible API (default: Groq).
 *
 * Supports a fallback provider chain — when the primary is rate-limited (429),
 * subsequent providers are tried before the job releases back to the queue.
 * All providers must be OpenAI-compatible (same chat/completions format).
 *
 * Normalizes and extracts structured fields from restaurant data:
 * - cuisines (normalized list)
 * - normalized address
 * - price_range (estimated)
 * - gap fields (phone, website_url, description)
 *
 * NEVER produces ratings - only structural/attribute fields.
 * Gracefully degrades to no-op when no key is configured.
 */
class AiEnrichmentService
{
    /**
     * @param  array<string, mixed>  $restaurantData
     * @return array<string, mixed>|null
     */
    public function enrichRestaurant(array $restaurantData): ?array
    {
        $providers = $this->buildProviderChain();

        if (empty($providers)) {
            return null;
        }

        $lastException = null;

        foreach ($providers as $provider) {
            if (empty($provider['api_key'])) {
                continue;
            }

            try {
                $result = $this->tryProvider($restaurantData, $provider);
                if ($result !== null) {
                    return $result;
                }

                return null;
            } catch (RequestException $e) {
                $lastException = $e;

                if ($e->response->status() !== 429) {
                    Log::warning('AI provider returned non-retryable error', [
                        'provider' => $provider['base_url'],
                        'status' => $e->response->status(),
                        'restaurant_id' => $restaurantData['id'] ?? null,
                    ]);

                    return null;
                }

                Log::info('AI provider rate-limited, trying fallback', [
                    'provider' => $provider['base_url'],
                    'restaurant_id' => $restaurantData['id'] ?? null,
                ]);
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning('AI provider threw exception, trying fallback', [
                    'provider' => $provider['base_url'],
                    'message' => $e->getMessage(),
                    'restaurant_id' => $restaurantData['id'] ?? null,
                ]);
            }
        }

        Log::warning('All AI providers exhausted', [
            'restaurant_id' => $restaurantData['id'] ?? null,
            'last_error' => $lastException?->getMessage(),
        ]);

        throw new \RuntimeException('All AI providers rate-limited or failed');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildProviderChain(): array
    {
        $primaryKey = config('services.ai.api_key');
        if (empty($primaryKey)) {
            return [];
        }

        $providers = [
            [
                'api_key' => $primaryKey,
                'base_url' => config('services.ai.base_url', 'https://api.groq.com/openai/v1'),
                'model' => config('services.ai.model', 'llama-3.3-70b-versatile'),
            ],
        ];

        foreach (config('services.ai.fallback', []) as $fallback) {
            if (! empty($fallback['api_key'])) {
                $providers[] = $fallback;
            }
        }

        return $providers;
    }

    /**
     * @param  array<string, mixed>  $restaurantData
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>|null
     */
    private function tryProvider(array $restaurantData, array $provider): ?array
    {
        $prompt = $this->buildPrompt($restaurantData);

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer '.$provider['api_key'],
                'Content-Type' => 'application/json',
            ])
            ->post("{$provider['base_url']}/chat/completions", [
                'model' => $provider['model'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a data normalization assistant for restaurant data. Extract structured information and normalize it. NEVER invent ratings or scores - only extract structural/attribute fields that are present or can be reasonably inferred. Return valid JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            Log::warning('AI enrichment request failed', [
                'provider' => $provider['base_url'],
                'status' => $response->status(),
                'restaurant_id' => $restaurantData['id'] ?? null,
            ]);

            if ($response->status() === 429) {
                throw new RequestException($response);
            }

            return null;
        }

        $data = $response->json();
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (empty($content)) {
            return null;
        }

        $parsed = json_decode($content, true);

        if (! is_array($parsed)) {
            Log::warning('AI enrichment returned invalid JSON', [
                'provider' => $provider['base_url'],
                'content' => $content,
                'restaurant_id' => $restaurantData['id'] ?? null,
            ]);

            return null;
        }

        unset($parsed['rating'], $parsed['review_count'], $parsed['score']);

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $restaurantData
     */
    private function buildPrompt(array $restaurantData): string
    {
        $name = $restaurantData['name'] ?? 'Unknown';
        $address = $restaurantData['address'] ?? null;
        $city = $restaurantData['city'] ?? null;
        $state = $restaurantData['state'] ?? null;
        $postalCode = $restaurantData['postal_code'] ?? null;
        $phone = $restaurantData['phone'] ?? null;
        $website = $restaurantData['website_url'] ?? null;
        $description = $restaurantData['description'] ?? null;

        $prompt = "Extract and normalize structured data for this restaurant:\n\n";
        $prompt .= "Name: {$name}\n";

        if ($address) {
            $prompt .= "Address: {$address}\n";
        }
        if ($city) {
            $prompt .= "City: {$city}\n";
        }
        if ($state) {
            $prompt .= "State: {$state}\n";
        }
        if ($postalCode) {
            $prompt .= "Postal Code: {$postalCode}\n";
        }
        if ($phone) {
            $prompt .= "Phone: {$phone}\n";
        }
        if ($website) {
            $prompt .= "Website: {$website}\n";
        }
        if ($description) {
            $prompt .= "Description: {$description}\n";
        }

        $prompt .= "\nReturn a JSON object with these fields (only include fields with values):\n";
        $prompt .= "- normalized_address: full normalized street address\n";
        $prompt .= "- phone: normalized phone number (if present and can be normalized)\n";
        $prompt .= "- website_url: normalized/cleaned website URL (if present)\n";
        $prompt .= "- price_range: estimated price level ($/$$/$$$/$$$$) based on cuisine type and location\n";
        $prompt .= "- cuisines: array of cuisine types (e.g., [\"Italian\", \"Pizza\"])\n";
        $prompt .= "- description: brief description (if missing and can be inferred)\n";
        $prompt .= "\nDO NOT include: rating, review_count, score, or any ratings fields.";

        return $prompt;
    }
}
