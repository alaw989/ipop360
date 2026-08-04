<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use Illuminate\Console\Command;
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
        $this->newLine();

        Log::channel('enrichment')->info('Restaurant coverage report', array_merge(
            ['total' => $total],
            $coverage,
            ['needing_ai_fields' => $needingAi]
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
