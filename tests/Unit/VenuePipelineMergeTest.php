<?php

namespace Tests\Unit;

use App\Services\VenuePipeline;
use Tests\TestCase;

/**
 * spec-079: mergeVenues / crossSourceDedup must carry `place_types` + `description`
 * across a merge. Previously they were dropped, so when a rich SerpApi row
 * ("Thai restaurant" type + description) folded into a name-only OSM/BizData
 * target, the merged row lost exactly the fields stampCuisineMatchStrength
 * (spec-071) reads → genuine cuisine matches stamped 0.0 and got demoted.
 */
class VenuePipelineMergeTest extends TestCase
{
    private VenuePipeline $pipeline;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pipeline = $this->app->make(VenuePipeline::class);
    }

    /** @return array<string, mixed> */
    private function nameOnlyOverpassVenue(): array
    {
        return [
            'name' => 'Siam Orchid',
            'source' => 'overpass',
            'lat' => 40.0,
            'lng' => -74.0,
            'google_rating' => null,
            'google_review_count' => 0,
            // no place_types, no description — the bug case
        ];
    }

    /** @return array<string, mixed> */
    private function richSerpApiVenue(): array
    {
        return [
            'name' => 'Siam Orchid',
            'source' => 'serpapi',
            'lat' => 40.0,
            'lng' => -74.0,
            'google_rating' => 4.6,
            'google_review_count' => 200,
            'place_types' => ['Thai restaurant'],
            'description' => 'Authentic Thai cuisine',
        ];
    }

    public function test_merge_carries_place_types_and_description_into_name_only_target(): void
    {
        $merged = $this->pipeline->mergeVenues($this->nameOnlyOverpassVenue(), $this->richSerpApiVenue());

        $this->assertContains('Thai restaurant', $merged['place_types'] ?? [], 'place_types carried across the merge');
        $this->assertSame('Authentic Thai cuisine', $merged['description'] ?? null, 'description carried across the merge');
    }

    public function test_cross_source_dedup_preserves_cuisine_signal_after_merge(): void
    {
        $deduped = $this->pipeline->crossSourceDedup([
            $this->nameOnlyOverpassVenue(),
            $this->richSerpApiVenue(),
        ]);

        $this->assertCount(1, $deduped, 'same-name/same-location venues merge into one');
        $this->assertContains('Thai restaurant', $deduped[0]['place_types'] ?? []);
        $this->assertSame('Authentic Thai cuisine', $deduped[0]['description'] ?? null);
    }

    public function test_merge_unions_place_types_from_both_sources(): void
    {
        $target = ['name' => 'X', 'place_types' => ['Restaurant', 'bar'], 'lat' => 1.0, 'lng' => 1.0];
        $source = ['name' => 'X', 'place_types' => ['bar', 'cafe'], 'lat' => 1.0, 'lng' => 1.0];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame(['Restaurant', 'bar', 'cafe'], $merged['place_types'], 'unioned + deduped');
    }

    public function test_merge_keeps_existing_description(): void
    {
        $target = ['name' => 'X', 'description' => 'Target desc', 'lat' => 1.0, 'lng' => 1.0];
        $source = ['name' => 'X', 'description' => 'Source desc', 'lat' => 1.0, 'lng' => 1.0];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame('Target desc', $merged['description'], 'existing target description is preferred');
    }

    /**
     * spec-094: normalizeResults defaults google_review_count/yelp_review_count
     * to 0 (not null) on every free source, so the generic `$targetValue ===
     * null` fold gate never fires for them. A target with no google rating at
     * all (both fields at their zero/null sentinels) merging a SerpApi source
     * with a real rating + review count must take the source's numbers.
     */
    public function test_merge_folds_review_count_from_source_when_target_has_no_google_rating(): void
    {
        $target = [
            'name' => 'X', 'lat' => 1.0, 'lng' => 1.0,
            'google_rating' => null, 'google_review_count' => 0,
        ];
        $source = [
            'name' => 'X', 'lat' => 1.0, 'lng' => 1.0,
            'google_rating' => 4.6, 'google_review_count' => 5000,
        ];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame(4.6, $merged['google_rating']);
        $this->assertSame(5000, $merged['google_review_count']);
    }

    /**
     * A target that already has its OWN google rating is preferred outright —
     * merging must not overwrite a real rating/review_count with a different
     * source's numbers.
     */
    public function test_merge_prefers_target_google_rating_when_both_present(): void
    {
        $target = [
            'name' => 'X', 'lat' => 1.0, 'lng' => 1.0,
            'google_rating' => 4.2, 'google_review_count' => 100,
        ];
        $source = [
            'name' => 'X', 'lat' => 1.0, 'lng' => 1.0,
            'google_rating' => 4.6, 'google_review_count' => 5000,
        ];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame(4.2, $merged['google_rating']);
        $this->assertSame(100, $merged['google_review_count']);
    }

    /**
     * spec-094 (generalized): a target already carrying a YELP rating must not
     * block a GOOGLE rating/review_count from folding in from a source that
     * only has google data — the two rating families are independent. Before
     * the fix, a single "target has ANY rating" flag skipped the whole
     * rating-family block once the target had a yelp_rating, even though its
     * google fields were still at their zero/null sentinels.
     */
    public function test_merge_folds_google_rating_independently_of_existing_yelp_rating(): void
    {
        $target = [
            'name' => 'X', 'lat' => 1.0, 'lng' => 1.0,
            'yelp_rating' => 3.9, 'yelp_review_count' => 40,
            'google_rating' => null, 'google_review_count' => 0,
        ];
        $source = [
            'name' => 'X', 'lat' => 1.0, 'lng' => 1.0,
            'yelp_rating' => null, 'yelp_review_count' => 0,
            'google_rating' => 4.6, 'google_review_count' => 5000,
        ];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame(3.9, $merged['yelp_rating'], 'existing yelp rating untouched');
        $this->assertSame(40, $merged['yelp_review_count'], 'existing yelp review count untouched');
        $this->assertSame(4.6, $merged['google_rating'], 'google rating folds in from source');
        $this->assertSame(5000, $merged['google_review_count'], 'google review count folds in from source');
    }

    /**
     * website_url/opening_hours are already in mergeVenues's field allowlist —
     * lock in that a SerpApi website survives a fold onto a name-only target.
     */
    public function test_merge_carries_website_url_onto_name_only_target(): void
    {
        $target = ['name' => 'X', 'lat' => 1.0, 'lng' => 1.0, 'website_url' => null];
        $source = ['name' => 'X', 'lat' => 1.0, 'lng' => 1.0, 'website_url' => 'https://example.com'];

        $merged = $this->pipeline->mergeVenues($target, $source);

        $this->assertSame('https://example.com', $merged['website_url']);
    }
}
