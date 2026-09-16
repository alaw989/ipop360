<?php

namespace Database\Seeders;

use App\Models\Cuisine;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Deterministic fixture data for the Playwright E2E suite.
 *
 * The mobile specs need a restaurant page that always has phone, website and
 * coordinates (so RestaurantActionBar renders all three actions), plus a signed
 * -out-able user. Real seed data was removed in spec-019, so the specs cannot
 * depend on rows from the dev/prod DB. This seeder is idempotent and is called
 * explicitly (`php artisan db:seed --class=E2ESeeder`) by the CI e2e job and by
 * the local stack before `npm run test:e2e`.
 *
 * Keep the slug/email in sync with e2e/mobile.spec.ts and e2e/overflow.spec.ts.
 */
class E2ESeeder extends Seeder
{
    /** Fixture restaurant slug — referenced verbatim by the e2e specs. */
    public const RESTAURANT_SLUG = 'e2e-fixture-kitchen';

    /** Fixture user email — referenced verbatim by the e2e specs. */
    public const USER_EMAIL = 'e2e@example.com';

    public function run(): void
    {
        // Cuisines/categories are a precondition for the search + detail pages.
        $this->call(CuisineSeeder::class);

        $restaurant = Restaurant::where('slug', self::RESTAURANT_SLUG)->first()
            ?? new Restaurant(['slug' => self::RESTAURANT_SLUG]);

        // A recovered/hand-built dev SQLite can lack PRIMARY KEY autoincrement
        // (the AGENTS.md recovery script rebuilds tables from a schema dump), so
        // an insert without an explicit id hits a NOT NULL failure there even
        // though a fresh `migrate` auto-assigns. max(id)+1 inserts cleanly on
        // both; on a fresh database it is simply 1.
        if (! $restaurant->exists) {
            $restaurant->id = ((int) Restaurant::max('id')) + 1;
        }

        $restaurant->fill([
            'name' => 'E2E Fixture Kitchen',
            'description' => 'Deterministic fixture venue used by the Playwright mobile suite.',
            'address' => '1 Test Plaza',
            'city' => 'Austin',
            'state' => 'TX',
            'postal_code' => '78701',
            'country' => 'US',
            'latitude' => 30.2672,
            'longitude' => -97.7431,
            'phone' => '+1 512-555-0100',
            'website_url' => 'https://example.com/e2e-fixture-kitchen',
            'price_range' => '$$',
            // No external photo: a remote image keeps the page from ever
            // reaching networkidle, which makes the specs flaky (and would hit
            // the network in CI). The detail/search layouts handle a missing
            // photo with their cuisine-gradient fallback.
            'photo_url' => null,
            'google_rating' => 4.5,
            'google_review_count' => 1200,
            'yelp_rating' => 4.4,
            'yelp_review_count' => 980,
            'popular_times_avg_busyness' => 55.0,
            'has_award' => false,
            'popularity_score' => 0.7500,
            'is_active' => true,
            'source' => 'e2e',
        ])->save();

        $cuisineIds = Cuisine::whereIn('slug', ['mexican', 'american'])->pluck('id');
        if ($cuisineIds->isNotEmpty()) {
            $restaurant->cuisines()->sync($cuisineIds->all());
        }

        $user = User::where('email', self::USER_EMAIL)->first()
            ?? new User(['email' => self::USER_EMAIL]);

        if (! $user->exists) {
            $user->id = ((int) User::max('id')) + 1;
        }

        $user->fill([
            'name' => 'E2E User',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'role' => 'user',
        ])->save();
    }
}
