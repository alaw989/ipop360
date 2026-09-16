<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Report field-coverage gaps across the restaurant corpus.
 *
 * Read-only: queries the restaurants table to print per-field coverage
 * percentages and a "completeness-eligible" count (rows missing the AI
 * fillable fields). Lets operators track whether enrichment is closing gaps.
 */
class RestaurantCoverageCommand extends Command
{
    protected $signature = 'restaurants:coverage';

    protected $description = 'Report field coverage across all restaurants (read-only)';

    public function handle(): int
    {
        $total = Restaurant::count();

        if ($total === 0) {
            $this->warn('No restaurants found.');

            return self::SUCCESS;
        }

        $fields = [
            'website_url' => "website_url IS NOT NULL AND website_url != ''",
            'phone' => "phone IS NOT NULL AND phone != ''",
            'opening_hours' => "opening_hours IS NOT NULL AND opening_hours != '[]'",
            'photo_url' => "photo_url IS NOT NULL AND photo_url != ''",
            'price_range' => "price_range IS NOT NULL AND price_range != ''",
            'description' => "description IS NOT NULL AND description != ''",
            'google_rating' => 'google_rating IS NOT NULL AND google_rating > 0',
            'menu_url' => "menu_url IS NOT NULL AND menu_url != ''",
        ];

        $coverage = [];
        foreach ($fields as $label => $condition) {
            $coverage[$label] = Restaurant::whereRaw($condition)->count();
        }

        $coverage['social_links'] = Restaurant::where('social_links_count', '>', 0)->count();
        $coverage['ai_metadata'] = Restaurant::whereRaw(
            "ai_metadata IS NOT NULL AND ai_metadata != '[]' AND ai_metadata != 'null'"
        )->count();

        $needingAi = Restaurant::where(function ($q) {
            $q->where(function ($q) {
                $q->whereNull('price_range')
                    ->orWhere('price_range', '')
                    ->orWhereNull('description')
                    ->orWhere('description', '')
                    ->orWhereNull('phone')
                    ->orWhere('phone', '');
            });
        })->count();

        $this->newLine();
        $this->line("<options=bold>Restaurant Field Coverage</> ({$total} total)");

        $rows = array_merge($fields, [
            'social_links' => 'social_links_count > 0',
            'ai_metadata' => 'ai_metadata populated',
        ]);

        foreach ($rows as $label => $_condition) {
            $count = $coverage[$label];
            $pct = round(($count / $total) * 100, 1);
            $bar = $this->renderBar($count, $total);
            $this->line(sprintf('  %-18s %6d  %5.1f%%  %s', $label, $count, $pct, $bar));
        }

        $this->newLine();
        $this->line('<options=bold>AI fillable gaps</> (missing price_range, description, or phone)');
        $this->line("  {$needingAi} of {$total} restaurants");

        // Geographic coverage: how much of the corpus sits in the configured
        // enrichment grid vs. the demand/bulk-seeded long tail, and how many
        // cities are single-row fragments. Lets enrichment changes (the
        // staleness rotation, Census-place seeding) be measured over time.
        $gridCities = array_map(
            fn ($city) => strtolower((string) $city),
            array_keys(config('restaurant-finder.cities', []))
        );
        $withCity = Restaurant::whereRaw("city IS NOT NULL AND city != ''")->count();

        $inGrid = 0;
        if ($gridCities !== []) {
            $placeholders = implode(',', array_fill(0, count($gridCities), '?'));
            $inGrid = Restaurant::whereRaw("LOWER(city) IN ({$placeholders})", $gridCities)->count();
        }

        $distinctCities = Restaurant::whereRaw("city IS NOT NULL AND city != ''")
            ->distinct()
            ->count('city');

        $fragmented = DB::query()->fromSub(
            Restaurant::query()
                ->selectRaw('city, state, COUNT(*) as n')
                ->whereRaw("city IS NOT NULL AND city != ''")
                ->groupBy('city', 'state'),
            'place_counts'
        )->where('n', '<=', 5)->count();

        $thinStates = DB::table('restaurants')
            ->selectRaw('state, COUNT(*) as n')
            ->whereRaw("state IS NOT NULL AND state != ''")
            ->groupBy('state')
            ->orderBy('n')
            ->limit(5)
            ->get();

        $this->newLine();
        $this->line('<options=bold>Geographic coverage</>');
        $this->line(sprintf('  %-22s %6d', 'rows with a city', $withCity));
        $this->line(sprintf('  %-22s %6d', 'in grid', $inGrid));
        $this->line(sprintf('  %-22s %6d', 'non-grid', $withCity - $inGrid));
        $this->line(sprintf('  %-22s %6d', 'distinct cities', $distinctCities));
        $this->line(sprintf('  %-22s %6d', 'cities <=5 rows', $fragmented));
        $this->line('  lowest-coverage states: '.$thinStates->map(fn ($row) => "{$row->state} ({$row->n})")->implode(', '));
        $this->newLine();

        Log::channel('enrichment')->info('Restaurant coverage report', array_merge(
            ['total' => $total],
            $coverage,
            [
                'needing_ai_fields' => $needingAi,
                'rows_with_city' => $withCity,
                'rows_in_grid' => $inGrid,
                'rows_outside_grid' => $withCity - $inGrid,
                'distinct_cities' => $distinctCities,
                'fragmented_cities' => $fragmented,
            ]
        ));

        return self::SUCCESS;
    }

    private function renderBar(int $count, int $total): string
    {
        $width = 20;
        $filled = (int) round(($count / $total) * $width);

        return '['.str_repeat('#', $filled).str_repeat('-', max(0, $width - $filled)).']';
    }
}
