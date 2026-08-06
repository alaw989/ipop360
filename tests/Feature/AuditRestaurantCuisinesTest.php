<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\Restaurant;
use Database\Seeders\CuisineSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditRestaurantCuisinesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CuisineSeeder::class);
    }

    private function restaurant(string $name, array $cuisineSlugs, array $extra = []): Restaurant
    {
        $restaurant = Restaurant::factory()->create(array_merge([
            'name' => $name,
            'description' => null,
        ], $extra));
        if (! empty($cuisineSlugs)) {
            $restaurant->cuisines()->attach(Cuisine::whereIn('slug', $cuisineSlugs)->pluck('id'));
        }

        return $restaurant->fresh();
    }

    private function tags(Restaurant $restaurant): array
    {
        return $restaurant->fresh()->cuisines->pluck('slug')->sort()->values()->all();
    }

    public function test_keeps_neutral_tag_without_evidence(): void
    {
        // "Arco Iris" carries no cuisine keyword and no rival signal — the tag
        // is neutral, so it is KEPT. The old audit dropped any tag without a
        // lexicon keyword, which false-dropped correct tags ("Mr. Dumpling" →
        // chinese). Absence of a keyword is not proof the tag is wrong.
        $r = $this->restaurant('Arco Iris', ['vietnamese', 'mexican']);

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame(['mexican', 'vietnamese'], $this->tags($r));
    }

    public function test_drops_tag_contradicted_by_name(): void
    {
        // Positive rival contradiction: the name signals a DIFFERENT cuisine
        // ("Oishi Ramen" = Japanese), so the fabricated chinese tag is dropped.
        $r = $this->restaurant('Oishi Ramen', ['chinese']);

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame([], $this->tags($r));
    }

    public function test_keeps_tag_supported_by_name(): void
    {
        $r = $this->restaurant("Tony's Pizza", ['italian', 'vietnamese']);

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame(['italian'], $this->tags($r));
    }

    public function test_keeps_tag_supported_by_description(): void
    {
        $r = $this->restaurant(
            'Bella Vita',
            ['italian'],
            ['description' => 'Authentic Italian pasta and wood-fired pizza'],
        );

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame(['italian'], $this->tags($r));
    }

    public function test_collapses_same_category_multi_tags_to_best_evidence(): void
    {
        // Old enrichment stamped every member of a searched category (all 5
        // Middle-Eastern cuisines) onto one venue. Collapse to the single
        // best-evidence member; the name "Turkish Flame" supports turkish.
        $r = $this->restaurant(
            'Turkish Flame Mediterranean Restaurant',
            ['lebanese', 'turkish', 'israeli', 'moroccan', 'egyptian'],
        );

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame(['turkish'], $this->tags($r));
    }

    public function test_leaves_cross_category_fusion_untouched(): void
    {
        // chinese|vietnamese span different categories — legit fusion survives.
        $r = $this->restaurant('3 Chefs Chinese & Vietnamese Food', ['chinese', 'vietnamese']);

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame(['chinese', 'vietnamese'], $this->tags($r));
    }

    public function test_adds_ai_backed_tag_and_drops_conflicting_tag(): void
    {
        $r = $this->restaurant("D'Corazon Mexican Restaurant", ['tex-mex'], [
            'ai_metadata' => ['cuisines' => ['Mexican']],
        ]);

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame(['mexican'], $this->tags($r));
    }

    public function test_normalizes_ai_cuisine_names_to_slugs(): void
    {
        $r = $this->restaurant('Bayou Kitchen', [], [
            'ai_metadata' => ['cuisines' => ['Cajun/Creole', 'Tex-Mex', 'Pizza']],
        ]);

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true, '--all' => true]);

        // 'Pizza' has no seeded cuisine (it's an Italian keyword) → not added.
        $this->assertSame(['cajun-creole', 'tex-mex'], $this->tags($r));
    }

    public function test_dry_run_does_not_persist(): void
    {
        $r = $this->restaurant('Oishi Ramen', ['chinese']);

        $this->artisan('restaurants:audit-cuisines');

        $this->assertSame(['chinese'], $this->tags($r));
    }

    public function test_cuisine_filter_only_audits_matching_restaurants(): void
    {
        $vietnamese = $this->restaurant('Oishi Ramen', ['vietnamese']);
        $mexican = $this->restaurant('Arco Iris 2', ['mexican']);

        $this->artisan('restaurants:audit-cuisines', ['--cuisine' => 'vietnamese', '--apply' => true]);

        $this->assertSame([], $this->tags($vietnamese));
        $this->assertSame(['mexican'], $this->tags($mexican));
    }

    public function test_untagged_restaurants_are_skipped_without_all_flag(): void
    {
        $r = $this->restaurant('Bayou Kitchen', [], ['ai_metadata' => ['cuisines' => ['Tex-Mex']]]);

        $this->artisan('restaurants:audit-cuisines', ['--apply' => true]);

        $this->assertSame([], $this->tags($r));
    }
}
