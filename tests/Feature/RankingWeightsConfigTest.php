<?php

namespace Tests\Feature;

use App\Services\PopularityScoreService;
use Illuminate\Support\Env;
use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the spec-104 rebalanced default weight set (docs/ranking-metrics.md).
 * The `ranking:audit` diagnostic (app/Console/Commands/RankingAuditCommand.php)
 * surfaced that a stale `RANK_WEIGHT_*` block in a deployment `.env` reverted
 * the spec-104 rebalance (data_completeness 0.25 vs 0.05, has_award 0.10 vs
 * 0.05, google_rating/google_review_count 0.03/0.02 vs 0.0). These tests pin
 * BOTH weight sources — the config file's `env()` defaults and the service's
 * `DEFAULT_WEIGHTS` fallback — so the two cannot drift from each other or from
 * the documented set without a test failing.
 */
class RankingWeightsConfigTest extends TestCase
{
    /** @return array<string, float> */
    private function spec104Weights(): array
    {
        return [
            'quality' => 0.35,
            'proximity' => 0.15,
            'data_completeness' => 0.05,
            'has_award' => 0.05,
            'cuisine_match' => 0.50,
            'google_rating' => 0.0,
            'google_review_count' => 0.0,
            'popular_times_avg_busyness' => 0.0,
            'social_links_count' => 0.20,
            'website_clicks_count' => 0.20,
            'pageviews_count' => 0.10,
            'social_link_clicks_count' => 0.05,
            'menu_click_count' => 0.05,
            'directions_clicks_count' => 0.05,
            'call_clicks_count' => 0.05,
        ];
    }

    /** @return list<string> */
    private function rankWeightEnvKeys(): array
    {
        return [
            'RANK_WEIGHT_QUALITY',
            'RANK_WEIGHT_PROXIMITY',
            'RANK_WEIGHT_DATA_COMPLETENESS',
            'RANK_WEIGHT_HAS_AWARD',
            'RANK_WEIGHT_CUISINE_MATCH',
            'RANK_WEIGHT_GOOGLE_RATING',
            'RANK_WEIGHT_GOOGLE_REVIEW_COUNT',
            'RANK_WEIGHT_POPULAR_TIMES',
            'RANK_WEIGHT_SOCIAL_LINKS_COUNT',
            'RANK_WEIGHT_WEBSITE_CLICKS',
            'RANK_WEIGHT_PAGEVIEWS',
            'RANK_WEIGHT_SOCIAL_LINK_CLICKS',
            'RANK_WEIGHT_MENU_CLICKS',
            'RANK_WEIGHT_DIRECTIONS_CLICKS',
            'RANK_WEIGHT_CALL_CLICKS',
        ];
    }

    public function test_config_file_weight_defaults_match_spec_104(): void
    {
        // Clear any RANK_WEIGHT_* overrides (deployment .env drift was the bug),
        // reset the immutable Env repository so env() re-reads the cleared
        // superglobals, then require the config file fresh to observe its
        // DEFAULT literals (not the boot-time resolved values).
        $keys = $this->rankWeightEnvKeys();
        $saved = [];
        foreach ($keys as $key) {
            $saved[$key] = [
                'getenv' => getenv($key),
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
            ];
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }

        $this->resetEnvRepository();

        try {
            $loaded = require config_path('restaurant-finder.php');
            $actual = array_map('floatval', $loaded['ranking']['weights']);

            $this->assertSame($this->spec104Weights(), $actual);
        } finally {
            foreach ($saved as $key => $prev) {
                if ($prev['getenv'] !== false) {
                    putenv($key.'='.$prev['getenv']);
                } else {
                    putenv($key);
                }
                if ($prev['server'] !== null) {
                    $_SERVER[$key] = $prev['server'];
                } else {
                    unset($_SERVER[$key]);
                }
                if ($prev['env'] !== null) {
                    $_ENV[$key] = $prev['env'];
                } else {
                    unset($_ENV[$key]);
                }
            }
        }
    }

    public function test_service_default_weights_match_spec_104(): void
    {
        $constant = (new ReflectionClass(PopularityScoreService::class))->getConstant('DEFAULT_WEIGHTS');

        $this->assertIsArray($constant);
        $this->assertSame($this->spec104Weights(), $constant);
    }

    private function resetEnvRepository(): void
    {
        $property = (new ReflectionClass(Env::class))->getProperty('repository');
        $property->setValue(null, null);
    }
}
