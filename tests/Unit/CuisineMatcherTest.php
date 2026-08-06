<?php

namespace Tests\Unit;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Services\CuisineMatcher;
use Database\Seeders\CuisineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CuisineMatcherTest extends TestCase
{
    use RefreshDatabase;

    private CuisineMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CuisineSeeder::class);
        $this->matcher = $this->app->make(CuisineMatcher::class);
    }

    /**
     * The key invariant: the config lexicon must cover EVERY cuisine the seeder
     * offers. A missing entry silently degraded that cuisine's search to weak
     * literal-slug matching — the original bug (only ~10 of 49 cuisines mapped).
     */
    public function test_drift_guard_every_db_cuisine_has_a_config_keyword_set(): void
    {
        $configCuisines = config('cuisine-keywords.cuisines', []);
        $slugs = Cuisine::pluck('slug')->unique()->all();

        $this->assertNotEmpty($slugs, 'CuisineSeeder produced no cuisines');

        foreach ($slugs as $slug) {
            $this->assertNotEmpty(
                $configCuisines[$slug] ?? null,
                "Cuisine '{$slug}' is missing from config/cuisine-keywords.php"
            );
        }
    }

    /**
     * The config 'categories' membership must mirror the seeded DB taxonomy, so
     * "All <Category>" searches resolve to the right member cuisines.
     */
    public function test_drift_guard_config_categories_match_db_taxonomy(): void
    {
        foreach (CuisineCategory::with('cuisines')->get() as $category) {
            $dbMembers = $category->cuisines->pluck('slug')->sort()->values()->all();
            $configMembers = collect(
                $this->matcher->resolveScope(null, $category->slug)->targetSlugs
            )->sort()->values()->all();

            $this->assertSame(
                $dbMembers,
                $configMembers,
                "Category '{$category->slug}' config members diverge from the DB taxonomy"
            );
        }
    }

    public function test_resolves_single_cuisine_scope(): void
    {
        $scope = $this->matcher->resolveScope('ethiopian', null);

        $this->assertTrue($scope->isScoped());
        $this->assertFalse($scope->isUnscoped());
        $this->assertSame(['ethiopian'], $scope->targetSlugs);
        $this->assertContains('injera', $scope->onKeywords);
        $this->assertSame('Ethiopian', $scope->queryTerm);
        $this->assertSame('ethiopian', $scope->primarySlug);
    }

    public function test_resolves_category_scope_to_member_cuisines(): void
    {
        $scope = $this->matcher->resolveScope(null, 'african');

        $this->assertTrue($scope->isScoped());
        $this->assertSame(
            ['ethiopian', 'nigerian', 'south-african', 'west-african', 'kenyan'],
            $scope->targetSlugs
        );
        // The umbrella ON set is the union of all member cuisines' keywords.
        $this->assertContains('injera', $scope->onKeywords);  // ethiopian
        $this->assertContains('jollof', $scope->onKeywords);  // nigerian / west-african
        $this->assertContains('braai', $scope->onKeywords);   // south-african
        $this->assertSame('African', $scope->queryTerm);
        $this->assertSame('african', $scope->primarySlug);
    }

    public function test_unscoped_when_nothing_requested(): void
    {
        $scope = $this->matcher->resolveScope(null, null);

        $this->assertTrue($scope->isUnscoped());
        $this->assertFalse($scope->isScoped());
        $this->assertEmpty($scope->targetSlugs);
        $this->assertEmpty($scope->onKeywords);
    }

    public function test_invalid_when_unknown_cuisine(): void
    {
        $scope = $this->matcher->resolveScope('not-a-real-cuisine', null);

        $this->assertTrue($scope->isInvalid());
        $this->assertFalse($scope->isScoped());
    }

    public function test_invalid_when_unknown_category(): void
    {
        $scope = $this->matcher->resolveScope(null, 'not-a-real-category');

        $this->assertTrue($scope->isInvalid());
    }

    /**
     * A category slug arriving in the cuisine param (e.g. a legacy
     * /restaurants?cuisine=african link) resolves as a category, not invalid.
     */
    public function test_category_slug_in_cuisine_param_resolves(): void
    {
        $scope = $this->matcher->resolveScope('african', null);

        $this->assertTrue($scope->isScoped());
        $this->assertContains('ethiopian', $scope->targetSlugs);
    }

    public function test_rival_keywords_exclude_the_on_set(): void
    {
        $scope = $this->matcher->resolveScope('chinese', null);

        // 'wok' is a chinese ON keyword → must never be flagged as rival.
        $this->assertContains('wok', $scope->onKeywords);
        $this->assertNotContains('wok', $scope->rivalKeywords);
        // A clearly rival keyword is present (sushi is Japanese, not Chinese).
        $this->assertContains('sushi', $scope->rivalKeywords);
    }

    /**
     * spec-080: a shared dish term claimed by one cuisine must NOT be a rival
     * for another cuisine whose lexicon contains it as a substring of a longer
     * on-cuisine keyword — otherwise a genuine on-cuisine venue described only
     * by that shared term (e.g. a Jamaican place described "Caribbean Curry")
     * is false-dropped. Drift guard mirroring the spa⊂spanish lesson.
     */
    public function test_rival_keywords_exclude_substrings_of_on_keywords(): void
    {
        $cases = [
            ['jamaican', 'curry'],   // ⊂ curry.goat
            ['malaysian', 'roti'],   // ⊂ roti.canai
            ['nepalese', 'roti'],    // ⊂ sel.roti
            ['greek', 'pita'],       // ⊂ spanakopita
            ['japanese', 'kaya'],    // ⊂ izakaya
            ['argentine', 'milan'],  // ⊂ milanesa
            // Documented trade-off (review): pho (vietnamese) ⊂ pholourie (trinidadian).
            // Excluding pho from rivals is recall-protective for Vietnamese pho places
            // on other-cuisine searches, at a small precision cost on trinidadian
            // searches. Kept intentional; suppress via an explicit allowlist if undesired.
            ['trinidadian', 'pho'],
        ];

        foreach ($cases as [$searchSlug, $sharedTerm]) {
            $scope = $this->matcher->resolveScope($searchSlug, null);

            $this->assertNotContains(
                $sharedTerm,
                $scope->rivalKeywords,
                "'{$sharedTerm}' must not rival a '{$searchSlug}' search (substring of an on-cuisine keyword)"
            );
        }
    }

    public function test_humanize_handles_multiword_slugs(): void
    {
        $this->assertSame('South African', $this->matcher->humanize('south-african'));
        $this->assertSame('Tex Mex', $this->matcher->humanize('tex-mex'));
    }

    public function test_matches_evidence_true_for_on_cuisine_keywords(): void
    {
        $this->assertTrue($this->matcher->matchesEvidence("Tony's Pizza", 'italian'));
        $this->assertTrue($this->matcher->matchesEvidence('Pho 24 Saigon', 'vietnamese'));
        $this->assertTrue($this->matcher->matchesEvidence('Taco Bell', 'mexican'));
    }

    public function test_matches_evidence_false_for_unrelated_names(): void
    {
        $this->assertFalse($this->matcher->matchesEvidence('Arco Iris', 'vietnamese'));
        $this->assertFalse($this->matcher->matchesEvidence("Jimmy's Grill", 'italian'));
        $this->assertFalse($this->matcher->matchesEvidence('Applebee\'s', 'vietnamese'));
    }

    public function test_matches_evidence_false_for_unknown_or_empty_input(): void
    {
        $this->assertFalse($this->matcher->matchesEvidence('anything', 'not-a-cuisine'));
        $this->assertFalse($this->matcher->matchesEvidence('', 'italian'));
        $this->assertFalse($this->matcher->matchesEvidence('   ', 'italian'));
    }

    public function test_matches_rival_evidence_true_when_name_signals_another_cuisine(): void
    {
        // "Oishi Ramen" tagged chinese: the name signals Japanese, a rival.
        $this->assertTrue($this->matcher->matchesRivalEvidence('Oishi Ramen', 'chinese'));
        // "Pho 9" tagged korean: the name signals Vietnamese, a rival.
        $this->assertTrue($this->matcher->matchesRivalEvidence('Pho 9', 'korean'));
        // "Doner Kebab Express" tagged greek: kebab signals Turkish, a rival.
        $this->assertTrue($this->matcher->matchesRivalEvidence('Doner Kebab Express', 'greek'));
    }

    public function test_matches_rival_evidence_false_for_neutral_names(): void
    {
        // A neutral name is not contradicted by any other cuisine.
        $this->assertFalse($this->matcher->matchesRivalEvidence('Arco Iris', 'mexican'));
        $this->assertFalse($this->matcher->matchesRivalEvidence('Mr. Dumpling', 'chinese'));
    }

    public function test_matches_rival_evidence_ignores_shared_keywords(): void
    {
        // "mediterranean" is a keyword of lebanese AND other ME cuisines — a
        // shared keyword must not count as a rival contradiction.
        $this->assertFalse($this->matcher->matchesRivalEvidence('Mediterranean Sandwich Co.', 'lebanese'));
        $this->assertFalse($this->matcher->matchesRivalEvidence('Halal Grill', 'lebanese'));
    }

    public function test_matches_rival_evidence_false_for_unknown_cuisine(): void
    {
        $this->assertFalse($this->matcher->matchesRivalEvidence('anything', 'not-a-cuisine'));
        $this->assertFalse($this->matcher->matchesRivalEvidence('', 'italian'));
    }

    public function test_lexicon_additions_recognize_real_venue_names(): void
    {
        // Data-backed additions: names that carry a cuisine word the search
        // lexicon was too tight to include (verified single-cuisine in prod).
        $this->assertTrue($this->matcher->matchesEvidence('Great Wall', 'chinese'));
        $this->assertTrue($this->matcher->matchesEvidence('Hong Kong Teahouse', 'chinese'));
        $this->assertTrue($this->matcher->matchesEvidence('Taste Of India', 'indian'));
        $this->assertTrue($this->matcher->matchesEvidence('Bombay Palace', 'indian'));
        $this->assertTrue($this->matcher->matchesEvidence('Texas de Brazil', 'brazilian'));
    }

    public function test_venue_matches_cuisine_checks_name_types_and_description(): void
    {
        // name evidence
        $this->assertTrue($this->matcher->venueMatchesCuisine(
            ['name' => 'Pho Saigon', 'place_types' => [], 'description' => null],
            'vietnamese',
        ));

        // place_types evidence (Google structured types, e.g. "vietnamese_restaurant")
        $this->assertTrue($this->matcher->venueMatchesCuisine(
            ['name' => "Jimmy's", 'place_types' => ['restaurant', 'vietnamese_restaurant'], 'description' => null],
            'vietnamese',
        ));

        // description evidence
        $this->assertTrue($this->matcher->venueMatchesCuisine(
            ['name' => "Jimmy's", 'place_types' => [], 'description' => 'Authentic banh mi and pho'],
            'vietnamese',
        ));

        // no evidence anywhere
        $this->assertFalse($this->matcher->venueMatchesCuisine(
            ['name' => 'Arco Iris', 'place_types' => [], 'description' => null],
            'vietnamese',
        ));
    }
}
