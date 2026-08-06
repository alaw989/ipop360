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

    public function test_drops_tags_without_evidence(): void
    {
        $r = $this->restaurant('Arco Iris', ['vietnamese', 'mexican']);

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
        $r = $this->restaurant('Arco Iris', ['vietnamese']);

        $this->artisan('restaurants:audit-cuisines');

        $this->assertSame(['vietnamese'], $this->tags($r));
    }

    public function test_cuisine_filter_only_audits_matching_restaurants(): void
    {
        $vietnamese = $this->restaurant('Arco Iris', ['vietnamese']);
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
