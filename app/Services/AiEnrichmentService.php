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
    private const SYSTEM_PROMPT = 'You are a data normalization assistant for restaurant data. Extract structured information and normalize it. NEVER invent ratings or scores - only extract structural/attribute fields that are present or can be reasonably inferred. Return valid JSON only.';

    /**
     * @param  array<string, mixed>  $restaurantData
     * @return array<string, mixed>|null
     */
    public function enrichRestaurant(array $restaurantData): ?array
    {
        $parsed = $this->callProviders($this->buildPrompt($restaurantData));

        if ($parsed === null) {
            return null;
        }

        unset($parsed['rating'], $parsed['review_count'], $parsed['score']);

        return $parsed;
    }

    /**
     * Re-derive a restaurant's name from whatever identifying data is present.
     *
     * Used by the data-hygiene pass to salvage junk rows (one-char names,
     * empty shells) before they are hard-deleted. Returns null when the model
     * cannot confidently identify the restaurant (or no AI key is configured).
     *
     * @param  array<string, mixed>  $restaurantData
     */
    public function rederiveName(array $restaurantData): ?string
    {
        $parsed = $this->callProviders($this->buildNameRederivationPrompt($restaurantData));

        if ($parsed === null) {
            return null;
        }

        $name = $parsed['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return trim($name);
    }

    /**
     * Run a prompt through the provider chain, returning the parsed JSON
     * response (or null when the model produced nothing usable).
     *
     * @return array<string, mixed>|null
     */
    private function callProviders(string $prompt): ?array
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
                return $this->tryProvider($prompt, $provider);
            } catch (\Throwable $e) {
                $status = $e instanceof RequestException ? $e->response->status() : null;

                if ($status !== null && ! $this->isRetryableStatus($status)) {
                    Log::warning('AI provider returned non-retryable error', [
                        'provider' => $provider['base_url'],
                        'status' => $status,
                    ]);

                    return null;
                }

                $lastException = $e;
                Log::warning('AI provider failed, trying fallback', [
                    'provider' => $provider['base_url'],
                    'message' => $e->getMessage(),
                    'status' => $status,
                ]);
            }
        }

        Log::warning('All AI providers exhausted', [
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
                'model' => config('services.ai.model', 'openai/gpt-oss-120b'),
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
     * A response status is retryable (fail over to the next provider) when it
     * indicates the provider is rate-limited (429), temporarily unavailable
     * (5xx), or otherwise failing server-side. Client errors that are never
     * going to succeed on another provider — 400/401/403/404 — are a hard stop.
     */
    private function isRetryableStatus(int $status): bool
    {
        if ($status === 429 || $status >= 500) {
            return true;
        }

        return in_array($status, [408, 425, 409], true);
    }

    /**
     * @param  array<string, mixed>  $provider
     * @return array<string, mixed>|null
     */
    private function tryProvider(string $prompt, array $provider): ?array
    {
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
                        'content' => self::SYSTEM_PROMPT,
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
            $status = $response->status();

            if ($this->isRetryableStatus($status)) {
                throw new RequestException($response);
            }

            Log::warning('AI enrichment request failed', [
                'provider' => $provider['base_url'],
                'status' => $status,
            ]);

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
            ]);

            return null;
        }

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

    /**
     * @param  array<string, mixed>  $restaurantData
     */
    private function buildNameRederivationPrompt(array $restaurantData): string
    {
        $name = $restaurantData['name'] ?? null;
        $address = $restaurantData['address'] ?? null;
        $city = $restaurantData['city'] ?? null;
        $state = $restaurantData['state'] ?? null;
        $postalCode = $restaurantData['postal_code'] ?? null;
        $phone = $restaurantData['phone'] ?? null;
        $website = $restaurantData['website_url'] ?? null;

        $prompt = "A restaurant record has a missing or invalid name. Identify the correct restaurant name from the available data.\n\n";

        if ($name) {
            $prompt .= "Current name: {$name}\n";
        }
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

        $prompt .= "\nReturn a JSON object with a single field:\n";
        $prompt .= "- name: the corrected restaurant name\n";
        $prompt .= "\nIf you cannot determine the name, return {\"name\": null}.";

        return $prompt;
    }
}
