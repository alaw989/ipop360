<?php

namespace App\Console\Commands;

use App\Models\Cuisine;
use App\Services\RestaurantEnrichmentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EnrichRestaurants extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'restaurants:enrich {city? : City name, or omit with --all-cities}
                            {--cuisine=* : Specific cuisine slugs to enrich}
                            {--all-cities : Run enrichment for all configured cities}
                            {--discovered : Enrich cities discovered from the DB that are not in the config}
                            {--throttled : Run throttled enrichment (quota-protected, cache-aware rotation)}
                            {--free-only : Skip SerpApi, use only free sources (BizData, Overpass, Socrata)}';

    /**
     * The console command description.
     */
    protected $description = 'Enrich restaurants for a city (or all configured cities)';

    /**
     * Execute the console command.
     */
    public function handle(RestaurantEnrichmentService $enrichmentService): int
    {
        $throttled = $this->option('throttled');
        $freeOnly = $this->option('free-only');

        if ($throttled) {
            if ($freeOnly) {
                $this->warn('--free-only is redundant with --throttled (throttled enrichment already limits SerpApi calls). Running throttled enrichment.');
            }

            return $this->enrichThrottled($enrichmentService);
        }

        $allCities = $this->option('all-cities');
        $discovered = $this->option('discovered');
        $cityArg = $this->argument('city');

        if ($allCities && $discovered) {
            $this->info('Running configured cities first, then discovered cities...');
            $result = $this->enrichAllCities($enrichmentService, $freeOnly);
            $this->newLine();
            $this->enrichDiscoveredCities($enrichmentService, $freeOnly);

            return $result;
        }

        if ($allCities) {
            $this->info('Free-only mode: '.($freeOnly ? 'ON (skipping SerpApi)' : 'OFF'));

            return $this->enrichAllCities($enrichmentService, $freeOnly);
        }

        if ($discovered) {
            return $this->enrichDiscoveredCities($enrichmentService, $freeOnly);
        }

        if (empty($cityArg)) {
            $this->error('Either provide a city name, use --all-cities, use --discovered, or use --throttled for quota-protected enrichment.');

            return self::FAILURE;
        }

        return $this->enrichSingleCity($enrichmentService, $cityArg, $freeOnly);
    }

    /**
     * Enrich restaurants for a single city.
     */
    protected function enrichSingleCity(RestaurantEnrichmentService $enrichmentService, string $city, bool $freeOnly = false): int
    {
        $this->info("Starting restaurant enrichment for city: {$city}".($freeOnly ? ' (free sources only)' : ''));

        $coordinates = $this->resolveCityCoordinates($city);

        if ($coordinates === null) {
            $this->error("Could not resolve coordinates for city: {$city}");

            return self::FAILURE;
        }

        [$lat, $lng] = $coordinates;

        $this->info("Coordinates: {$lat}, {$lng}");

        $cuisines = $this->getCuisines();

        if ($cuisines->isEmpty()) {
            $this->warn('No cuisines found. Run db:seed --class=CuisineSeeder first.');

            return self::FAILURE;
        }

        $totalEnriched = 0;

        $stateCode = config('restaurant-finder.city_states.'.$city);

        foreach ($cuisines as $cuisine) {
            $this->info("Searching for {$cuisine->name} restaurants...");

            try {
                $count = $enrichmentService->enrichByCuisine($lat, $lng, $cuisine, $freeOnly, $city, $stateCode);
                $totalEnriched += $count;
                $this->info("  -> Enriched {$count} {$cuisine->name} restaurants");
            } catch (\Throwable $e) {
                $this->error("  -> Failed for {$cuisine->name}: {$e->getMessage()}");
            }
        }

        $this->info("Enrichment complete. Total restaurants enriched: {$totalEnriched}");
        Log::channel('enrichment')->info('Single-city enrichment complete', [
            'city' => $city,
            'total_enriched' => $totalEnriched,
        ]);

        return self::SUCCESS;
    }

    /**
     * Enrich restaurants for all configured cities.
     */
    protected function enrichAllCities(RestaurantEnrichmentService $enrichmentService, bool $freeOnly = false): int
    {
        /** @var array<string, array{float, float}> $cities */
        $cities = config('restaurant-finder.cities', []);

        if (empty($cities)) {
            $this->warn('No cities configured in config/restaurant-finder.php');

            return self::FAILURE;
        }

        $this->info('Starting enrichment for all configured cities'.($freeOnly ? ' (free sources only)' : '').':');
        $this->table(['City', 'Latitude', 'Longitude'], collect($cities)->map(fn ($coords, $name) => [
            $name,
            $coords[0],
            $coords[1],
        ]));

        $cuisines = $this->getCuisines();

        if ($cuisines->isEmpty()) {
            $this->warn('No cuisines found. Run db:seed --class=CuisineSeeder first.');

            return self::FAILURE;
        }

        $grandTotal = 0;
        /** @var array<string, int> $cityResults */
        $cityResults = [];

        foreach ($cities as $cityName => $coordinates) {
            $this->newLine();
            $this->info("Processing {$cityName}...");

            [$lat, $lng] = $coordinates;

            $stateCode = config('restaurant-finder.city_states.'.$cityName);

            $cityTotal = 0;

            foreach ($cuisines as $cuisine) {
                try {
                    $count = $enrichmentService->enrichByCuisine($lat, $lng, $cuisine, $freeOnly, $cityName, $stateCode);
                    $cityTotal += $count;
                    $this->info("  -> {$cuisine->name}: {$count} restaurants");
                } catch (\Throwable $e) {
                    $this->error("  -> {$cuisine->name} failed: {$e->getMessage()}");
                }
            }

            $cityResults[$cityName] = $cityTotal;
            $grandTotal += $cityTotal;

            $this->info("{$cityName}: {$cityTotal} restaurants enriched");
        }

        $this->newLine();
        $this->table(['City', 'Enriched'], collect($cityResults)->map(fn ($count, $city) => [$city, $count]));
        $this->info("Grand total: {$grandTotal} restaurants enriched across ".count($cities).' cities.');
        Log::channel('enrichment')->info('All-cities enrichment complete', [
            'total_enriched' => $grandTotal,
            'cities_count' => count($cities),
            'city_results' => $cityResults,
        ]);

        return self::SUCCESS;
    }

    /**
     * Enrich restaurants for cities discovered in the database that are not
     * in the configured cities list. These are cities that were added via
     * live search persistence or other means.
     */
    protected function enrichDiscoveredCities(RestaurantEnrichmentService $enrichmentService, bool $freeOnly = false): int
    {
        $configured = config('restaurant-finder.cities', []);
        $configuredLower = array_map(fn ($k) => strtolower((string) $k), array_keys($configured));

        // Find distinct city/state pairs from the DB that may not be configured
        $discovered = DB::table('restaurants')
            ->selectRaw('city, state, AVG(latitude) as avg_lat, AVG(longitude) as avg_lng, COUNT(*) as cnt')
            ->whereNotNull('city')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0)
            ->groupBy('city', 'state')
            ->orderByDesc('cnt')
            ->get()
            ->filter(fn ($row) => ! in_array(strtolower($row->city), $configuredLower, true))
            ->values();

        if ($discovered->isEmpty()) {
            $this->info('No discovered cities to enrich (all DB cities are already configured).');

            return self::SUCCESS;
        }

        $this->info('Enriching '.count($discovered).' discovered cities not in config:');
        $this->table(
            ['City', 'State', 'Avg Lat', 'Avg Lng', 'Restaurants'],
            $discovered->map(fn ($r) => [$r->city, $r->state ?? '?', round($r->avg_lat, 4), round($r->avg_lng, 4), $r->cnt])
        );

        $cuisines = $this->getCuisines();

        if ($cuisines->isEmpty()) {
            $this->warn('No cuisines found. Run db:seed --class=CuisineSeeder first.');

            return self::FAILURE;
        }

        $grandTotal = 0;
        /** @var array<string, int> $cityResults */
        $cityResults = [];

        foreach ($discovered as $row) {
            $cityName = $row->city;
            $this->newLine();
            $this->info("Processing discovered city: {$cityName}...");

            [$lat, $lng] = [$row->avg_lat, $row->avg_lng];
            $stateCode = $row->state;

            $cityResults[$cityName] = 0;

            foreach ($cuisines as $cuisine) {
                try {
                    $count = $enrichmentService->enrichByCuisine($lat, $lng, $cuisine, $freeOnly, $cityName, $stateCode);
                } catch (\Throwable $e) {
                    $this->error("  -> {$cuisine->name} failed: {$e->getMessage()}");
                    $count = 0;
                }

                $totalForCuisine = $count;
                $cityResults[$cityName] += $totalForCuisine;
                $grandTotal += $totalForCuisine;
                $this->info("  -> {$cuisine->name}: {$totalForCuisine} restaurants");
            }

            $this->info("{$cityName}: {$cityResults[$cityName]} restaurants enriched");
        }

        $this->newLine();
        $this->table(['City', 'Enriched'], collect($cityResults)->map(fn ($count, $city) => [$city, $count]));
        $this->info("Grand total: {$grandTotal} restaurants enriched across ".count($discovered).' discovered cities.');
        Log::channel('enrichment')->info('Discovered-cities enrichment complete', [
            'total_enriched' => $grandTotal,
            'cities_count' => count($discovered),
            'city_results' => $cityResults,
        ]);

        return self::SUCCESS;
    }

    /**
     * Get cuisines to enrich, either from --cuisine option or configured cuisines.
     *
     * @return Collection<int, Cuisine>
     */
    protected function getCuisines(): Collection
    {
        $cuisineSlugs = $this->option('cuisine');

        if (! empty($cuisineSlugs)) {
            return Cuisine::whereIn('slug', $cuisineSlugs)->get();
        }

        $configuredCuisines = config('restaurant-finder.cuisines', []);

        if (empty($configuredCuisines)) {
            return Cuisine::all();
        }

        $slugs = array_map(fn (string $name) => str()->slug($name), $configuredCuisines);

        return Cuisine::whereIn('slug', $slugs)->get();
    }

    /**
     * Resolve a city name to latitude and longitude coordinates.
     *
     * @return array<int, float>|null
     */
    private function resolveCityCoordinates(string $city): ?array
    {
        $cities = config('restaurant-finder.cities', []);

        $key = strtolower(trim($city));

        if (isset($cities[$key])) {
            return $cities[$key];
        }

        // Check if city was provided as "City,State" or "City, Country"
        $parts = array_map('trim', explode(',', $city));
        foreach ($cities as $name => $coords) {
            foreach ($parts as $part) {
                if (strtolower($part) === strtolower($name) || str_contains(strtolower($name), strtolower($part))) {
                    return $coords;
                }
            }
        }

        return null;
    }

    /**
     * Enrich restaurants using throttled, quota-protected mode.
     * Rotates through city×cuisine combos, respecting cache and quotas.
     */
    protected function enrichThrottled(RestaurantEnrichmentService $enrichmentService): int
    {
        $this->info('Starting throttled enrichment (quota-protected)');
        Log::channel('enrichment')->info('Starting throttled enrichment (quota-protected)');

        $result = $enrichmentService->enrichAllCitiesThrottled();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Combos processed', $result['combos_processed']],
                ['Real SerpApi calls made', $result['real_calls_made']],
                ['Cache hits skipped', $result['cache_hits_skipped']],
                ['Combo cap reached?', $result['combos_cap_reached'] ? 'Yes' : 'No'],
                ['Max runtime reached?', $result['max_runtime_reached'] ? 'Yes' : 'No'],
                ['Quota exhausted?', $result['quota_exhausted'] ? 'Yes' : 'No'],
            ]
        );

        Log::channel('enrichment')->info('Throttled enrichment result', [
            'combos_processed' => $result['combos_processed'],
            'real_calls_made' => $result['real_calls_made'],
            'cache_hits_skipped' => $result['cache_hits_skipped'],
            'combos_cap_reached' => $result['combos_cap_reached'],
            'quota_exhausted' => $result['quota_exhausted'],
        ]);

        if ($result['quota_exhausted']) {
            $this->warn('Monthly quota exhausted or per-run cap reached. Consider increasing the budget or running more frequently.');
        }

        $this->info('Throttled enrichment complete');
        Log::channel('enrichment')->info('Throttled enrichment complete');

        return self::SUCCESS;
    }
}
