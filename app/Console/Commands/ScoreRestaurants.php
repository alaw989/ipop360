<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScoreRestaurants extends Command
{
    protected $signature = 'restaurants:score {--city= : Only score restaurants in this city}';

    protected $description = 'Recompute popularity scores for existing restaurants';

    public function handle(PopularityScoreService $scoringService): int
    {
        $city = $this->option('city');

        $query = Restaurant::query()->active();

        if ($city) {
            $query->where('city', 'like', "%{$city}%");
        }

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No restaurants found to score.');

            // A true-empty run is a no-op, not a crash — scheduler:health
            // treats FAILURE as a real alert-worthy problem.
            return self::SUCCESS;
        }

        $this->info('Snapshotting current rank order...');

        $oldOrder = Restaurant::active()
            ->when($city, fn ($q) => $q->where('city', 'like', "%{$city}%"))
            ->orderBy('popularity_score', 'desc')
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->values()
            ->toArray();

        $oldRanks = [];
        foreach ($oldOrder as $index => $id) {
            $oldRanks[$id] = $index + 1;
        }

        $this->info("Computing aggregates across {$total} restaurants...");

        $allRestaurants = Restaurant::active()->when($city, fn ($q) => $q->where('city', 'like', "%{$city}%"))->get();
        $aggregates = $scoringService->computeAggregates($allRestaurants);

        $this->info("Scoring {$total} restaurants in chunks...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $chunkSize = 500;
        $scored = 0;

        // Chunk processing for memory efficiency
        Restaurant::active()
            ->when($city, fn ($q) => $q->where('city', 'like', "%{$city}%"))
            ->chunkById($chunkSize, function ($restaurants) use ($scoringService, $aggregates, &$scored, $bar) {
                $updates = [];

                foreach ($restaurants as $restaurant) {
                    $breakdown = $scoringService->calculateBreakdownWithAggregatesFromEloquent(
                        $restaurant,
                        $aggregates
                    );

                    $updates[] = [
                        'id' => $restaurant->id,
                        'popularity_score' => $breakdown['total'],
                        'score_breakdown' => $breakdown,
                    ];

                    $bar->advance();
                }

                // Batch update within a transaction
                if (! empty($updates)) {
                    DB::transaction(function () use ($updates) {
                        foreach ($updates as $update) {
                            Restaurant::where('id', $update['id'])->update([
                                'popularity_score' => $update['popularity_score'],
                                'score_breakdown' => json_encode($update['score_breakdown']),
                            ]);
                        }
                    });

                    $scored += count($updates);
                }
            });

        $this->info('Computing rank changes...');

        $newOrder = Restaurant::active()
            ->when($city, fn ($q) => $q->where('city', 'like', "%{$city}%"))
            ->orderBy('popularity_score', 'desc')
            ->orderBy('id', 'asc')
            ->pluck('id')
            ->values()
            ->toArray();

        $bar = $this->output->createProgressBar(count($newOrder));
        $bar->start();

        collect($newOrder)->chunk(500)->each(function ($chunk) use ($oldRanks, $bar) {
            $caseRankChange = 'CASE id';
            $chunkIds = [];

            foreach ($chunk as $newRank => $id) {
                $newPosition = $newRank + 1;
                $oldPosition = $oldRanks[$id] ?? null;
                $change = $oldPosition !== null ? $oldPosition - $newPosition : null;
                $chunkIds[] = $id;
                $caseRankChange .= " WHEN {$id} THEN ".($change !== null ? $change : 'NULL');
                $bar->advance();
            }

            $caseRankChange .= ' END';
            $idsIn = implode(',', $chunkIds);

            DB::update("
                UPDATE restaurants
                SET rank_change = ({$caseRankChange})
                WHERE id IN ({$idsIn})
            ");
        });

        $bar->finish();
        $this->newLine();
        $this->info("Scoring complete. {$scored} restaurants scored.");
        Log::channel('enrichment')->info('Scoring complete', [
            'total' => $total,
            'scored' => $scored,
            'city' => $this->option('city') ?? 'all',
        ]);

        return self::SUCCESS;
    }
}
