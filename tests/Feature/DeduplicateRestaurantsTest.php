<?php

namespace Tests\Feature;

use App\Models\Cuisine;
use App\Models\CuisineCategory;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class DeduplicateRestaurantsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $extra
     */
    private function restaurant(string $name, string $city, float $lat, float $lng, array $extra = []): Restaurant
    {
        $model = Restaurant::factory()->create(array_merge([
            'name' => $name,
            'city' => $city,
            'latitude' => $lat,
            'longitude' => $lng,
            'photo_url' => null,
        ], $extra));

        return Restaurant::whereKey($model->id)->firstOrFail();
    }

    public function test_dry_run_does_not_delete(): void
    {
        $keep = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);
        $dupe = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:dedupe');
        $cmd->expectsOutputToContain('DRY RUN');

        $this->assertDatabaseHas('restaurants', ['id' => $keep->id]);
        $this->assertDatabaseHas('restaurants', ['id' => $dupe->id]);
    }

    public function test_apply_keeps_earlier_row_and_deletes_later(): void
    {
        $keep = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74, ['website_url' => 'https://keep.example']);
        $dupe = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);

        $this->artisan('restaurants:dedupe', ['--apply' => true]);

        $this->assertDatabaseHas('restaurants', ['id' => $keep->id]);
        $this->assertDatabaseMissing('restaurants', ['id' => $dupe->id]);
        $this->assertSame('https://keep.example', Restaurant::findOrFail($keep->id)->website_url);
    }

    public function test_repoints_social_links_and_recomputes_counter(): void
    {
        $keep = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);
        $dupe = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);

        RestaurantSocialLink::create(['restaurant_id' => $dupe->id, 'platform' => 'instagram', 'url' => 'https://ig.example/dupe']);

        $this->artisan('restaurants:dedupe', ['--apply' => true]);

        $this->assertDatabaseHas('restaurant_social_links', [
            'restaurant_id' => $keep->id,
            'platform' => 'instagram',
        ]);
        $this->assertSame(1, Restaurant::findOrFail($keep->id)->social_links_count);
    }

    public function test_drops_colliding_social_link_and_keeps_other(): void
    {
        $keep = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);
        $dupe = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);

        // Both rows have facebook — a true duplicate platform, keep the kept row's.
        RestaurantSocialLink::create(['restaurant_id' => $keep->id, 'platform' => 'facebook', 'url' => 'https://fb.example/keep']);
        RestaurantSocialLink::create(['restaurant_id' => $dupe->id, 'platform' => 'facebook', 'url' => 'https://fb.example/dupe']);
        // Only the dupe has twitter — repointed.
        RestaurantSocialLink::create(['restaurant_id' => $dupe->id, 'platform' => 'twitter', 'url' => 'https://tw.example/dupe']);

        $this->artisan('restaurants:dedupe', ['--apply' => true]);

        $links = RestaurantSocialLink::where('restaurant_id', $keep->id)->get();
        $this->assertSame(2, $links->count());
        $this->assertSame(1, $links->where('platform', 'facebook')->count());
        $this->assertSame(1, $links->where('platform', 'twitter')->count());
        $this->assertSame(2, Restaurant::findOrFail($keep->id)->social_links_count);
    }

    public function test_unions_cuisine_pivot_and_avoids_duplicate(): void
    {
        $keep = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);
        $dupe = $this->restaurant('Dupe Test', 'Austin', 30.26, -97.74);

        $category = CuisineCategory::create(['name' => 'European', 'slug' => 'european']);
        $italian = Cuisine::create(['name' => 'Italian', 'slug' => 'italian', 'category_id' => $category->id]);
        $french = Cuisine::create(['name' => 'French', 'slug' => 'french', 'category_id' => $category->id]);

        $keep->cuisines()->attach($italian->id);
        $dupe->cuisines()->attach([$italian->id, $french->id]);

        $this->artisan('restaurants:dedupe', ['--apply' => true]);

        $this->assertDatabaseHas('cuisine_restaurant', ['restaurant_id' => $keep->id, 'cuisine_id' => $italian->id]);
        $this->assertDatabaseHas('cuisine_restaurant', ['restaurant_id' => $keep->id, 'cuisine_id' => $french->id]);
        $this->assertSame(1, DB::table('cuisine_restaurant')->where('restaurant_id', $keep->id)->where('cuisine_id', $italian->id)->count());
    }

    public function test_leaves_distinct_venues_untouched(): void
    {
        $a = $this->restaurant('Same Name', 'Austin', 30.26, -97.74);
        $b = $this->restaurant('Same Name', 'Austin', 30.27, -97.75); // different coords

        $this->artisan('restaurants:dedupe', ['--apply' => true]);

        $this->assertDatabaseHas('restaurants', ['id' => $a->id]);
        $this->assertDatabaseHas('restaurants', ['id' => $b->id]);
    }
}
