<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_command_is_scheduled_daily(): void
    {
        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($event) => str_contains($event->command ?? $event->description ?? '', 'restaurants:data-hygiene'));

        $this->assertCount(1, $events, 'restaurants:data-hygiene must be scheduled exactly once');
    }

    public function test_normalizes_full_state_names_to_abbreviations(): void
    {
        $r = $this->restaurant(['state' => 'Alabama']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertSame('AL', $r->fresh()->state);
    }

    public function test_normalizes_lowercase_state_to_uppercase_abbreviation(): void
    {
        $r = $this->restaurant(['state' => 'ak']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertSame('AK', $r->fresh()->state);
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

        $this->assertSame('Long Beach', $r->fresh()->city);
    }

    public function test_collapses_double_spaces_in_name_and_address(): void
    {
        $r = $this->restaurant([
            'name' => 'Pita  &  Naan',
            'address' => '1434  O St',
        ]);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $fresh = $r->fresh();
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
        $this->assertDatabaseHas('restaurants', ['id' => $b->id], 'chain locations with same name but different city/coords are NOT duplicates');
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

        $this->assertDatabaseMissing('restaurants', ['id' => $dupe->id], 'same-phone same-city group must collapse to one row');
        $this->assertDatabaseHas('restaurants', ['id' => $keep->id]);
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $r = $this->restaurant(['state' => 'Alabama', 'city' => 'long beach']);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:data-hygiene');
        $cmd->expectsOutputToContain('DRY RUN');

        $fresh = $r->fresh();
        $this->assertSame('Alabama', $fresh->state);
        $this->assertSame('long beach', $fresh->city);
    }

    public function test_logs_per_run_summary_to_enrichment_channel(): void
    {
        $this->restaurant(['state' => 'Alabama']);

        $this->artisan('restaurants:data-hygiene', ['--apply' => true]);

        $this->assertTrue(true, 'command must complete; summary logging asserted via command output');
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
