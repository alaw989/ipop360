<?php

namespace Tests\Feature;

use App\Jobs\EnrichRestaurantWithAi;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * Contract for the continuous data-hygiene command (`restaurants:data-hygiene`).
 *
 * Live audit (8154 rows): state column mixed abbrev (`AK`) + full names
 * (`Alabama`) + junk (`Hauts-De-France`, `Île-De-France`); city lowercase on
 * ~all rows; double/trailing whitespace; phone unnormalized; true duplicates
 * (exact name+city+coords, and same-phone+city groups). Chain locations
 * (Applebee's ×270) are legit — never merged by name alone.
 *
 * Scheduled daily, bounded per run (a continuous pass over the corpus).
 */
class DataHygieneCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `.env` ships a real AI key, which would otherwise leak into these
        // tests and cause the enrichment pass to dispatch real HTTP calls.
        // Start every test with no key; tests opt in via mockAi()/config().
        config(['services.ai' => [
            'api_key' => '',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'fallback' => [],
        ]]);
    }

    public function test_command_is_scheduled_daily(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? $event->description ?? '', 'restaurants:data-hygiene'));

        $this->assertCount(1, $events, 'restaurants:data-hygiene must be scheduled exactly once');
        $this->assertStringContainsString('--apply --limit=200', $events->first()->command ?? '');
    }

    public function test_normalizes_full_state_names_to_abbreviations(): void
    {
        $r = $this->restaurant(['state' => 'Alabama']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('AL', $fresh->state);
    }

    public function test_normalizes_lowercase_state_to_uppercase_abbreviation(): void
    {
        $r = $this->restaurant(['state' => 'ak']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('AK', $fresh->state);
    }

    public function test_clears_non_us_junk_states_to_null(): void
    {
        foreach (['Hauts-De-France', 'Île-De-France', 'Ontario'] as $junk) {
            $this->restaurant(['state' => $junk]);
        }

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $states = Restaurant::pluck('state')->unique()->values()->all();
        $this->assertSame([null], $states, 'junk/foreign states must be cleared to null');
    }

    public function test_title_cases_city_names(): void
    {
        $r = $this->restaurant(['city' => 'long beach']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Long Beach', $fresh->city);
    }

    public function test_collapses_double_spaces_in_name_and_address(): void
    {
        $r = $this->restaurant([
            'name' => 'Pita  &  Naan',
            'address' => '1434  O St',
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Pita & Naan', $fresh->name);
        $this->assertSame('1434 O St', $fresh->address);
    }

    public function test_merges_exact_name_city_coords_duplicates(): void
    {
        $keep = $this->restaurant([
            'name' => 'Dupe Test',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
            'website_url' => 'https://keep.example',
        ]);
        $dupe = $this->restaurant([
            'name' => 'Dupe Test',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
        ]);

        RestaurantSocialLink::create(['restaurant_id' => $dupe->id, 'platform' => 'instagram', 'url' => 'https://ig.example/dupe']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertDatabaseHas('restaurants', ['id' => $keep->id]);
        $this->assertDatabaseMissing('restaurants', ['id' => $dupe->id]);
        $this->assertSame('https://keep.example', Restaurant::findOrFail($keep->id)->website_url);
        $this->assertDatabaseHas('restaurant_social_links', [
            'restaurant_id' => $keep->id,
            'platform' => 'instagram',
        ]);
    }

    public function test_does_not_merge_chain_locations_with_same_name(): void
    {
        $a = $this->restaurant([
            'name' => "Applebee's",
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
        ]);
        $b = $this->restaurant([
            'name' => "Applebee's",
            'city' => 'Houston',
            'state' => 'TX',
            'latitude' => 29.76,
            'longitude' => -95.36,
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertDatabaseHas('restaurants', ['id' => $a->id]);
        $this->assertDatabaseHas('restaurants', ['id' => $b->id]);
    }

    public function test_merges_same_phone_and_city_group(): void
    {
        $keep = $this->restaurant([
            'name' => 'Same Phone A',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
            'phone' => '5125550142',
        ]);
        $dupe = $this->restaurant([
            'name' => 'Same Phone B',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.2601,
            'longitude' => -97.7401,
            'phone' => '5125550142',
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertDatabaseMissing('restaurants', ['id' => $dupe->id]);
        $this->assertDatabaseHas('restaurants', ['id' => $keep->id]);
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $r = $this->restaurant(['state' => 'Alabama', 'city' => 'long beach']);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:data-hygiene');
        $cmd->expectsOutputToContain('DRY RUN');

        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Alabama', $fresh->state);
        $this->assertSame('long beach', $fresh->city);
    }

    public function test_logs_per_run_summary_to_enrichment_channel(): void
    {
        $this->restaurant(['state' => 'Alabama']);

        Log::shouldReceive('channel')->with('enrichment')->andReturnSelf();
        Log::shouldReceive('info')
            ->withArgs(fn ($message, $context) => $message === 'Data hygiene run complete'
                && is_array($context)
                && $context['state_normalized'] === 1)
            ->once();

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);
    }

    public function test_rederives_one_char_name_when_ai_returns_a_name(): void
    {
        $this->mockAi();

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['name' => 'Real Eatery'])]]],
            ]),
        ]);

        $r = $this->restaurant(['name' => 'X']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertDatabaseHas('restaurants', ['id' => $r->id]);
        $fresh = $r->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('Real Eatery', $fresh->name);
    }

    public function test_hard_deletes_junk_row_when_ai_returns_no_name(): void
    {
        $this->mockAi();

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['name' => null])]]],
            ]),
        ]);

        $r = $this->restaurant(['name' => 'X']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertDatabaseMissing('restaurants', ['id' => $r->id]);
    }

    public function test_skips_junk_deletion_when_no_ai_key(): void
    {
        config(['services.ai' => [
            'api_key' => '',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'fallback' => [],
        ]]);

        $r = $this->restaurant(['name' => 'X']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertDatabaseHas('restaurants', ['id' => $r->id]);
        Http::assertNothingSent();
    }

    public function test_detects_empty_shell_rows_as_junk_and_deletes_them(): void
    {
        $this->mockAi();

        Http::fake([
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode(['name' => null])]]],
            ]),
        ]);

        $r = $this->restaurant([
            'name' => '',
            'address' => null,
            'phone' => null,
            'website_url' => null,
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertDatabaseMissing('restaurants', ['id' => $r->id]);
    }

    public function test_dry_run_reports_junk_rows_without_deleting(): void
    {
        $r = $this->restaurant(['name' => 'X']);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:data-hygiene');
        $cmd->expectsOutputToContain('Junk rows detected: 1');

        $this->assertDatabaseHas('restaurants', ['id' => $r->id]);
    }

    public function test_apply_dispatches_enrichment_jobs_for_missing_fields_highest_score_first(): void
    {
        $this->mockAi();
        Queue::fake();

        $low = $this->restaurant([
            'name' => 'Low Score Eatery',
            'description' => null,
            'phone' => null,
            'website_url' => null,
            'price_range' => null,
            'popularity_score' => 0.1,
        ]);
        $high = $this->restaurant([
            'name' => 'High Score Eatery',
            'description' => null,
            'phone' => null,
            'website_url' => null,
            'price_range' => null,
            'popularity_score' => 0.9,
        ]);
        $complete = $this->restaurant([
            'name' => 'Complete Eatery',
            'description' => 'A full description.',
            'phone' => '5550100',
            'website_url' => 'https://full.example.com',
            'price_range' => '$$',
            'popularity_score' => 0.99,
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        /** @var array<int, EnrichRestaurantWithAi> $jobs */
        $jobs = Queue::pushed(EnrichRestaurantWithAi::class); /* @phpstan-ignore staticMethod.notFound */
        $pushed = collect($jobs)->map->restaurantId->all();

        $this->assertSame([$high->id, $low->id], $pushed);
        $this->assertNotContains($complete->id, $pushed);
    }

    public function test_enrichment_dispatch_is_bounded_to_daily_limit(): void
    {
        $this->mockAi();
        Queue::fake();

        for ($i = 0; $i < 205; $i++) {
            $this->restaurant([
                'name' => "Eatery {$i}",
                'description' => null,
                'phone' => null,
                'website_url' => null,
                'price_range' => null,
            ]);
        }

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        Queue::assertPushed(EnrichRestaurantWithAi::class, 200);
    }

    public function test_dry_run_reports_enrich_eligible_without_dispatching(): void
    {
        $this->mockAi();
        Queue::fake();

        $this->restaurant([
            'name' => 'Missing Fields Eatery',
            'description' => null,
            'phone' => null,
            'website_url' => null,
            'price_range' => null,
        ]);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:data-hygiene');
        $cmd->expectsOutputToContain('AI-enrich eligible: 1');

        Queue::assertNothingPushed();
    }

    public function test_skips_enrichment_dispatch_when_no_ai_key(): void
    {
        config(['services.ai' => [
            'api_key' => '',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'fallback' => [],
        ]]);
        Queue::fake();

        $this->restaurant([
            'name' => 'Missing Fields Eatery',
            'description' => null,
            'phone' => null,
            'website_url' => null,
            'price_range' => null,
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        Queue::assertNothingPushed();
    }

    public function test_limit_bounds_merge_pass_to_one_pair(): void
    {
        $a1 = $this->restaurant([
            'name' => 'Limit Merge A',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
        ]);
        $a2 = $this->restaurant([
            'name' => 'Limit Merge A',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
        ]);
        $b1 = $this->restaurant([
            'name' => 'Limit Merge B',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.27,
            'longitude' => -97.75,
        ]);
        $b2 = $this->restaurant([
            'name' => 'Limit Merge B',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.27,
            'longitude' => -97.75,
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true, '--limit' => 1]);

        $this->assertDatabaseHas('restaurants', ['id' => $a1->id]);
        $this->assertDatabaseMissing('restaurants', ['id' => $a2->id]);
        $this->assertDatabaseHas('restaurants', ['id' => $b1->id]);
        $this->assertDatabaseHas('restaurants', ['id' => $b2->id]);
    }

    public function test_limit_caps_enrich_dispatch(): void
    {
        $this->mockAi();
        Queue::fake();

        for ($i = 0; $i < 5; $i++) {
            $this->restaurant([
                'name' => "Eatery {$i}",
                'description' => null,
                'phone' => null,
                'website_url' => null,
                'price_range' => null,
            ]);
        }

        $this->artisan('restaurants:data-hygiene', ['--apply' => true, '--limit' => 2]);

        Queue::assertPushed(EnrichRestaurantWithAi::class, 2);
    }

    public function test_non_positive_limit_is_treated_as_unbounded(): void
    {
        $keep = $this->restaurant([
            'name' => 'Zero Limit Merge',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
        ]);
        $dupe = $this->restaurant([
            'name' => 'Zero Limit Merge',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true, '--limit' => 0]);

        $this->assertDatabaseMissing('restaurants', ['id' => $dupe->id]);
        $this->assertDatabaseHas('restaurants', ['id' => $keep->id]);
    }

    private function mockAi(): void
    {
        config(['services.ai' => [
            'api_key' => 'pk-test',
            'base_url' => 'https://api.groq.com/openai/v1',
            'model' => 'llama-3.3-70b-versatile',
            'fallback' => [],
        ]]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function restaurant(array $overrides = []): Restaurant
    {
        $model = Restaurant::factory()->create(array_merge([
            'name' => 'Test Eatery',
            'city' => 'Austin',
            'state' => 'TX',
            'latitude' => 30.26,
            'longitude' => -97.74,
        ], $overrides));

        return Restaurant::whereKey($model->id)->firstOrFail();
    }
}
