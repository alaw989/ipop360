<?php

namespace Tests\Unit;

use App\Services\VenuePipeline;
use Tests\TestCase;

/**
 * Regression coverage for the "Southern Bail Bonds Dallas" leak: a non-food
 * business (bail bonds) was enriched and persisted as a restaurant because
 * the non-restaurant filter (spec-042/046) only ran on the live-search read
 * path, never on RestaurantEnrichmentService's write path — and its untyped-row
 * NAME fallback was gated to `serpapi` only, so an untyped `bizdata` row (the
 * source that most plausibly produced this record — BizData is documented
 * elsewhere as ignoring its own `category` query param) got no name check at
 * all. Both gaps are now closed in VenuePipeline::filterNonRestaurants(),
 * which RestaurantEnrichmentService::enrichByCuisine() now calls before
 * persisting (previously it only called filterGarbageNames()).
 */
class VenuePipelineNonRestaurantTest extends TestCase
{
    private VenuePipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = $this->app->make(VenuePipeline::class);
    }

    public function test_drops_untyped_bizdata_row_with_bail_bonds_name(): void
    {
        $venues = [
            ['name' => 'Southern Bail Bonds Dallas', 'source' => 'bizdata', 'place_types' => []],
            ['name' => 'Southern Comfort Kitchen', 'source' => 'bizdata', 'place_types' => []],
        ];

        $kept = array_column($this->pipeline->filterNonRestaurants($venues), 'name');

        $this->assertNotContains('Southern Bail Bonds Dallas', $kept);
        $this->assertContains('Southern Comfort Kitchen', $kept);
    }

    public function test_drops_row_with_finance_legal_place_types(): void
    {
        $venues = [
            ['name' => 'Southern Bail Bonds Dallas', 'source' => 'bizdata', 'place_types' => ['bail bond agent']],
            ['name' => 'Downtown Law Office', 'source' => 'serpapi', 'place_types' => ['lawyer']],
            ['name' => 'First National', 'source' => 'serpapi', 'place_types' => ['bank']],
            ['name' => 'Real Diner', 'source' => 'serpapi', 'place_types' => ['restaurant']],
        ];

        $kept = array_column($this->pipeline->filterNonRestaurants($venues), 'name');

        $this->assertNotContains('Southern Bail Bonds Dallas', $kept);
        $this->assertNotContains('Downtown Law Office', $kept);
        $this->assertNotContains('First National', $kept);
        $this->assertContains('Real Diner', $kept);
    }

    public function test_does_not_false_positive_on_bank_themed_restaurant_name(): void
    {
        // 'bank' is deliberately NOT in the free-text NAME denylist (only in the
        // place_types list, which is safe to be broad) — a converted-bank-building
        // restaurant literally named "The Bank" is a real, known pattern. This
        // pins that the broadened bizdata name check doesn't repeat the
        // spa⊂spanish mistake for financial-service words.
        $venues = [
            ['name' => 'The Bank Steakhouse', 'source' => 'bizdata', 'place_types' => []],
        ];

        $kept = array_column($this->pipeline->filterNonRestaurants($venues), 'name');

        $this->assertContains('The Bank Steakhouse', $kept);
    }

    public function test_looks_non_restaurant_flags_persisted_row_by_place_types(): void
    {
        $reason = $this->pipeline->looksNonRestaurant([
            'name' => 'Southern Bail Bonds Dallas',
            'description' => null,
            'place_types' => ['bail bond agent'],
        ]);

        $this->assertNotNull($reason);
    }

    public function test_looks_non_restaurant_flags_persisted_row_by_name_when_untyped(): void
    {
        $reason = $this->pipeline->looksNonRestaurant([
            'name' => 'Southern Bail Bonds Dallas',
            'description' => 'Fast, cheapest and affordable 24/7 bail bonds in Dallas and Kaufman Counties.',
            'place_types' => null,
        ]);

        $this->assertNotNull($reason);
    }

    public function test_looks_non_restaurant_keeps_genuine_untyped_restaurant(): void
    {
        $reason = $this->pipeline->looksNonRestaurant([
            'name' => 'Southern Comfort Kitchen',
            'description' => 'Home-style Southern cooking and fried chicken.',
            'place_types' => null,
        ]);

        $this->assertNull($reason);
    }
}
