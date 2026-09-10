<?php

namespace App\Jobs;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Services\AiEnrichmentService;
use App\Services\CuisineMatcher;
use App\Services\CuisineTagMapper;
use App\Services\WebsiteIdentityVerifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async job to enrich a restaurant with AI-extracted data.
 *
 * Runs asynchronously so search latency is unaffected. The model has no
 * browsing — anything it "fills" for a missing field is recalled or invented —
 * so only what can be checked is stored as fact:
 *   - address: normalized only when the row has NONE (it used to overwrite
 *     existing addresses: 3,663 prod rows rewritten);
 *   - website_url: only when WebsiteIdentityVerifier confirms the page is
 *     this restaurant's (name + phone/street/city);
 *   - cuisines: attached to the pivot only when the venue's own name/place
 *     types corroborate them (CuisineMatcher) — the AI's word alone once
 *     tagged a bail-bonds office "Southern";
 *   - description: written (soft, clearly descriptive text) and flagged in
 *     ai_metadata;
 *   - phone, price_range: never written — kept in ai_metadata['inferred'].
 *     They render on cards and drive the price filter; a guess there is
 *     wrong data.
 */
class EnrichRestaurantWithAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 5;

    /**
     * Backoff between retries (seconds). Exponential: 30s, 60s, 120s, 240s.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 120, 240, 480];

    /**
     * Max exceptions before marking failed (prevents infinite retry on 429).
     */
    public int $maxExceptions = 5;

    /**
     * The maximum number of seconds the job should run.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $restaurantId
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(
        AiEnrichmentService $aiEnrichment,
        CuisineTagMapper $cuisineTagMapper,
        ?CuisineMatcher $cuisineMatcher = null,
        ?WebsiteIdentityVerifier $websiteVerifier = null,
    ): void {
        $cuisineMatcher ??= app(CuisineMatcher::class);
        $websiteVerifier ??= app(WebsiteIdentityVerifier::class);

        $restaurant = Restaurant::find($this->restaurantId);

        if ($restaurant === null) {
            Log::debug('AI enrichment job skipped: restaurant not found', [
                'restaurant_id' => $this->restaurantId,
            ]);

            return;
        }

        // Prepare restaurant data for AI enrichment
        $restaurantData = $restaurant->toArray();

        try {
            $enriched = $aiEnrichment->enrichRestaurant($restaurantData);

            if ($enriched === null) {
                Log::debug('AI enrichment returned no data (no key or error)', [
                    'restaurant_id' => $restaurant->id,
                ]);

                return;
            }

            // Update restaurant with enriched fields
            $updates = [];
            $aiMetadata = [
                'enriched_at' => now()->toISOString(),
                'fields_updated' => [],
                'inferred' => [],
                'model' => config('services.ai.model', 'openai/gpt-oss-120b'),
            ];

            // Normalized address: fill-empty only — never rewrite a sourced one.
            if (! empty($enriched['normalized_address']) && empty($restaurant->address)) {
                $updates['address'] = $enriched['normalized_address'];
                $aiMetadata['fields_updated'][] = 'address';
            }

            // Phone and price level can't be checked: keep as inference only.
            if (! empty($enriched['phone']) && empty($restaurant->phone)) {
                $aiMetadata['inferred']['phone'] = $enriched['phone'];
            }
            if (! empty($enriched['price_range']) && empty($restaurant->price_range)
                && in_array($enriched['price_range'], ['$', '$$', '$$$', '$$$$'], true)) {
                $aiMetadata['inferred']['price_range'] = $enriched['price_range'];
            }

            // Website: a suggestion, saved only if the page proves to be this
            // restaurant's own site.
            if (! empty($enriched['website_url']) && empty($restaurant->website_url) && is_string($enriched['website_url'])) {
                $verdict = $websiteVerifier->verify($restaurant, $enriched['website_url']);
                if ($verdict->acceptsNew()) {
                    $updates['website_url'] = $enriched['website_url'];
                    $updates['website_identity'] = WebsiteIdentityVerifier::VERIFIED;
                    $updates['website_verified_at'] = now();
                    $aiMetadata['fields_updated'][] = 'website_url';
                } else {
                    $aiMetadata['inferred']['website_url'] = $enriched['website_url'];
                }
            }

            // Update description if provided and missing
            if (! empty($enriched['description']) && empty($restaurant->description)) {
                $updates['description'] = $enriched['description'];
                $aiMetadata['fields_updated'][] = 'description';
            }

            // Cuisines: recorded in ai_metadata, but attached to the pivot
            // (where they drive cuisine browse/filtering) only when the
            // venue's own name/place types — or a description that predates
            // this AI pass — carry keyword evidence for that cuisine. Only
            // seeded cuisines are considered; existing tags are never detached.
            $cuisineIds = [];
            if (! empty($enriched['cuisines']) && is_array($enriched['cuisines'])) {
                $aiMetadata['cuisines'] = $enriched['cuisines'];
                $candidateIds = $cuisineTagMapper->idsForNames(array_values($enriched['cuisines']));
                $evidence = [
                    'name' => $restaurant->name,
                    'place_types' => $restaurant->place_types,
                    'description' => $restaurant->description,
                ];
                foreach (Cuisine::query()->whereIn('id', $candidateIds)->get(['id', 'slug']) as $cuisine) {
                    if ($cuisineMatcher->venueMatchesCuisine($evidence, (string) $cuisine->slug)) {
                        $cuisineIds[] = (int) $cuisine->id;
                    }
                }
                if ($cuisineIds !== []) {
                    $aiMetadata['fields_updated'][] = 'cuisines';
                }
            }

            // Provenance is cumulative: a field an EARLIER run wrote must stay
            // marked as AI-written (a re-run used to replace ai_metadata
            // wholesale, silently turning old guesses into "sourced" data).
            $previous = is_array($restaurant->ai_metadata) ? $restaurant->ai_metadata : [];
            $aiMetadata['fields_updated'] = array_values(array_unique([
                ...(is_array($previous['fields_updated'] ?? null) ? $previous['fields_updated'] : []),
                ...$aiMetadata['fields_updated'],
            ]));
            $aiMetadata['inferred'] = array_merge(
                is_array($previous['inferred'] ?? null) ? $previous['inferred'] : [],
                $aiMetadata['inferred']
            );

            // Store the ai_metadata
            $updates['ai_metadata'] = $aiMetadata;

            // Update the restaurant
            if (! empty($updates)) {
                DB::transaction(function () use ($restaurant, $updates, $aiMetadata, $cuisineIds) {
                    $restaurant->update($updates);

                    if ($cuisineIds !== []) {
                        $restaurant->cuisines()->syncWithoutDetaching($cuisineIds);
                    }

                    $changes = [];
                    foreach ($aiMetadata['fields_updated'] as $field) {
                        $changes[] = [
                            'field' => $field,
                            'old' => $restaurant->getOriginal($field) ?? null,
                            'new' => $updates[$field] ?? null,
                        ];
                    }

                    Log::channel('enrichment')->info('AI enrichment complete', [
                        'restaurant_id' => $restaurant->id,
                        'restaurant_name' => $restaurant->name,
                        'model' => $aiMetadata['model'],
                        'changes' => $changes,
                        'score_before' => $restaurant->getOriginal('popularity_score'),
                    ]);
                });
            } else {
                Log::channel('enrichment')->debug('AI enrichment produced no new fields', [
                    'restaurant_id' => $restaurant->id,
                ]);
            }
        } catch (\Throwable $e) {
            // Best-effort enrichment: a provider outage (all AI providers down /
            // rate-limited / unreachable) must NOT fail the job or 500 the
            // requesting live-search call. Log and skip this row — the next
            // scheduled enrichment pass retries it.
            Log::warning('AI enrichment job skipped (provider unavailable)', [
                'restaurant_id' => $restaurant->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::warning('AI enrichment job failed permanently', [
            'restaurant_id' => $this->restaurantId,
            'message' => $exception->getMessage(),
        ]);
    }
}
