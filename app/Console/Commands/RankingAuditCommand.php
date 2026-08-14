<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\PopularityScoreService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only popularity-score audit. Prints the active weight set, the
 * rated/unrated cohort split, the score distribution (overall and per-cohort),
 * per-signal activation rates, and score deciles. This is the data source for
 * weight rebalancing decisions: it shows which signals actually fire across
 * the corpus and how wide the score spread is, without writing anything.
 *
 * By default it reads the persisted `popularity_score` (a lagging indicator —
 * it only refreshes on the daily 02:00 run). `--recompute` instead forecasts
 * the distribution under the CURRENT weight set, so a weight change's effect
 * is visible immediately without waiting for the scheduler.
 */
class RankingAuditCommand extends Command
{
    protected $signature = 'ranking:audit {--city= : Only audit restaurants in this city} {--recompute : Recompute scores under the current weights instead of reading persisted popularity_score}';

    protected $description = 'Audit popularity-score distribution and signal activation (read-only)';

    public function handle(): int
    {
        $city = $this->option('city') ?: null;
        $recompute = (bool) $this->option('recompute');

        $query = Restaurant::query()->active()->when($city, fn ($q) => $q->where('city', 'like', "%{$city}%"));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No restaurants found to audit.');

            return self::SUCCESS;
        }

        if ($recompute) {
            $this->line('<options=bold>Recompute mode</>: scores below are freshly calculated under the current weight set; persisted `popularity_score` is ignored.');
            $this->newLine();
        }

        $forecast = $recompute ? $this->forecastScores($query) : null;

        $this->printWeights();

        $this->printCohorts($query, $total);

        $this->printDistribution($query, $total, $forecast);

        $this->printCohortOverlap($query, $total, $forecast);

        $this->printSignalActivation($query, $total);

        $this->printDeciles($query, $total, $forecast);

        return self::SUCCESS;
    }

    private function printWeights(): void
    {
        $weights = config('restaurant-finder.ranking.weights', []);
        $active = array_filter($weights, fn ($w) => (float) $w > 0.0);

        $this->newLine();
        $this->line('<options=bold>Active weight set</> (raw — renormalized per row over its active signals)');
        foreach ($active as $signal => $weight) {
            $this->line(sprintf('  %-28s %0.2f', $signal, (float) $weight));
        }
        $this->newLine();
    }

    /**
     * @param  Builder<Restaurant>  $query
     */
    private function printCohorts(Builder $query, int $total): void
    {
        $rated = (clone $query)->where('google_rating', '>', 0)->count();
        $unrated = $total - $rated;

        $this->line('<options=bold>Corpus</>');
        $this->line(sprintf('  Total:   %d', $total));
        $this->line(sprintf('  Rated:   %d (%0.1f%%)', $rated, $this->pct($rated, $total)));
        $this->line(sprintf('  Unrated: %d (%0.1f%%)', $unrated, $this->pct($unrated, $total)));
        $this->newLine();
    }

    /**
     * @param  Builder<Restaurant>  $query
     * @param  array<int, float>|null  $forecast
     */
    private function printDistribution(Builder $query, int $total, ?array $forecast = null): void
    {
        $scores = $this->scoreValues($query, $forecast);
        $ratedScores = $this->scoreValues((clone $query)->where('google_rating', '>', 0), $forecast);
        $unratedScores = $this->scoreValues(
            (clone $query)->where(fn ($q) => $q->whereNull('google_rating')->orWhere('google_rating', '<=', 0)),
            $forecast,
        );

        $source = $forecast === null ? 'persisted popularity_score' : 'recomputed under current weights';
        $this->line("<options=bold>Score distribution</> ({$source})");
        $this->line(sprintf('  Scored: %d of %d', count($scores), $total));
        $this->printStats('  overall ', $scores);
        $this->printStats('  rated   ', $ratedScores);
        $this->printStats('  unrated ', $unratedScores);
        $this->newLine();
    }

    /**
     * How far the rated and unrated cohorts bleed into each other. The rebalance
     * intended "rated dominates unrated" — this quantifies the exceptions: link-rich
     * unrated venues reaching into the rated tail (unrated above the lowest-rated
     * score) and weak rated venues undershooting the best unrated score.
     *
     * @param  Builder<Restaurant>  $query
     * @param  array<int, float>|null  $forecast
     */
    private function printCohortOverlap(Builder $query, int $total, ?array $forecast = null): void
    {
        $ratedScores = $this->scoreValues((clone $query)->where('google_rating', '>', 0), $forecast);
        $unratedScores = $this->scoreValues(
            (clone $query)->where(fn ($q) => $q->whereNull('google_rating')->orWhere('google_rating', '<=', 0)),
            $forecast,
        );

        if ($ratedScores === [] || $unratedScores === []) {
            $this->line('<options=bold>Cohort overlap</> — need both rated and unrated rows to compare');
            $this->newLine();

            return;
        }

        $ratedMin = min($ratedScores);
        $unratedMax = max($unratedScores);
        $unratedAboveRatedMin = count(array_filter($unratedScores, fn (float $s) => $s > $ratedMin));
        $ratedBelowUnratedMax = count(array_filter($ratedScores, fn (float $s) => $s < $unratedMax));

        $this->line('<options=bold>Cohort overlap</> (how far the two cohorts bleed into each other)');
        $this->line(sprintf('  rated min  %0.4f    unrated max %0.4f', $ratedMin, $unratedMax));
        $this->line(sprintf(
            '  unrated above rated min: %d (%0.1f%% of unrated)',
            $unratedAboveRatedMin,
            $this->pct($unratedAboveRatedMin, count($unratedScores)),
        ));
        $this->line(sprintf(
            '  rated below unrated max: %d (%0.1f%% of rated)',
            $ratedBelowUnratedMax,
            $this->pct($ratedBelowUnratedMax, count($ratedScores)),
        ));
        $this->newLine();
    }

    /**
     * @param  Builder<Restaurant>  $query
     */
    private function printSignalActivation(Builder $query, int $total): void
    {
        $signals = [
            'quality' => fn () => (clone $query)->where('google_rating', '>', 0)->count(),
            'social_links_count' => fn () => (clone $query)->where('social_links_count', '>', 0)->count(),
            'website_clicks_count' => fn () => (clone $query)->where('website_clicks_count', '>', 0)->count(),
            'pageviews_count' => fn () => (clone $query)->where('pageviews_count', '>', 0)->count(),
            'social_link_clicks_count' => fn () => (clone $query)->where('social_link_clicks_count', '>', 0)->count(),
            'menu_click_count' => fn () => (clone $query)->where('menu_click_count', '>', 0)->count(),
            'has_award' => fn () => (clone $query)->where('has_award', true)->count(),
            'data_completeness' => fn () => $total,
        ];

        $this->line('<options=bold>Signal activation</> (rows where the signal is active)');
        foreach ($signals as $signal => $counter) {
            $count = $counter();
            $this->line(sprintf('  %-28s %6d  %5.1f%%', $signal, $count, $this->pct($count, $total)));
        }
        $this->newLine();
    }

    /**
     * @param  Builder<Restaurant>  $query
     * @param  array<int, float>|null  $forecast
     */
    private function printDeciles(Builder $query, int $total, ?array $forecast = null): void
    {
        $scores = $this->scoreValues($query, $forecast);
        sort($scores);

        if ($scores === []) {
            $this->line('<options=bold>Score deciles</> — no scored rows');
            $this->newLine();

            return;
        }

        $this->line('<options=bold>Score deciles</>');
        foreach ([10, 25, 50, 75, 90, 95, 99] as $p) {
            $this->line(sprintf('  p%-2d  %0.4f', $p, $this->percentile($scores, $p)));
        }
        $this->newLine();
    }

    /**
     * @param  Builder<Restaurant>  $query
     * @param  array<int, float>|null  $forecast
     * @return list<float>
     */
    private function scoreValues(Builder $query, ?array $forecast = null): array
    {
        if ($forecast === null) {
            return array_values(
                (clone $query)->whereNotNull('popularity_score')->pluck('popularity_score')
                    ->map(fn ($v) => (float) $v)->all()
            );
        }

        return array_values(
            (clone $query)->get(['id'])
                ->map(fn (Restaurant $r) => $forecast[(int) $r->id] ?? null)
                ->filter(fn ($v) => $v !== null)
                ->map(fn ($v) => (float) $v)
                ->all()
        );
    }

    /**
     * Recompute every restaurant's score under the CURRENT weight set, bypassing
     * the persisted `popularity_score` (which only refreshes on the daily 02:00
     * score run — a lagging indicator). Lets the audit answer "what would the
     * distribution look like if the daily run happened right now?" without a
     * write or a wait. Reads only the columns the scorer touches.
     *
     * @param  Builder<Restaurant>  $query
     * @return array<int, float>
     */
    private function forecastScores(Builder $query): array
    {
        $all = (clone $query)->get($this->forecastColumns());

        if ($all->isEmpty()) {
            return [];
        }

        $service = app(PopularityScoreService::class);
        $aggregates = $service->computeAggregates($all);

        $scores = [];
        foreach ($all as $restaurant) {
            $scores[(int) $restaurant->id] = $service
                ->calculateBreakdownWithAggregatesFromEloquent($restaurant, $aggregates)['total'];
        }

        return $scores;
    }

    /**
     * Columns the scorer reads. Deliberately excludes the persisted
     * `popularity_score`/`score_breakdown` and heavy payload columns (photos,
     * ai_metadata, opening_hours) so a full-corpus recompute stays light.
     *
     * @return list<string>
     */
    private function forecastColumns(): array
    {
        return [
            'id', 'name', 'address', 'phone', 'latitude', 'longitude',
            'price_range', 'website_url', 'photo_url', 'features',
            'social_links_count', 'google_rating', 'google_review_count',
            'has_award', 'website_clicks_count', 'pageviews_count',
            'social_link_clicks_count', 'menu_click_count',
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function printStats(string $label, array $values): void
    {
        if ($values === []) {
            $this->line(sprintf('%s (n=0)', $label));

            return;
        }

        $this->line(sprintf(
            '%s n=%d  min %0.4f  max %0.4f  mean %0.4f  median %0.4f  sd %0.4f',
            $label,
            count($values),
            min($values),
            max($values),
            $this->mean($values),
            $this->percentile($values, 50),
            $this->stddev($values),
        ));
    }

    /**
     * @param  list<float>  $values
     */
    private function mean(array $values): float
    {
        return array_sum($values) / count($values);
    }

    /**
     * Population standard deviation.
     *
     * @param  list<float>  $values
     */
    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

        $mean = $this->mean($values);
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return sqrt($sum / $n);
    }

    /**
     * Linear-interpolated percentile (0-100). Values must already be sorted asc.
     *
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, int $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }

        $rank = ($p / 100.0) * ($n - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);

        if ($lower === $upper) {
            return $sorted[$lower];
        }

        $weight = $rank - $lower;

        return $sorted[$lower] * (1 - $weight) + $sorted[$upper] * $weight;
    }

    private function pct(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
    }
}
