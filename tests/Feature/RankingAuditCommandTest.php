<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RankingAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_weights_and_cohort_split(): void
    {
        Restaurant::factory()->create([
            'google_rating' => 4.5,
            'google_review_count' => 500,
            'social_links_count' => 2,
            'popularity_score' => 0.8,
        ]);
        Restaurant::factory()->freeOnly()->create([
            'google_rating' => null,
            'social_links_count' => 0,
            'popularity_score' => 0.2,
        ]);
        Restaurant::factory()->freeOnly()->create([
            'google_rating' => null,
            'social_links_count' => 0,
            'popularity_score' => 0.4,
        ]);

        Artisan::call('ranking:audit');

        $output = Artisan::output();

        $this->assertStringContainsString('Active weight set', $output);
        $this->assertStringContainsString('quality', $output);
        $this->assertStringContainsString('Total:   3', $output);
        $this->assertStringContainsString('Rated:   1 (33.3%)', $output);
        $this->assertStringContainsString('Unrated: 2 (66.7%)', $output);
    }

    public function test_audit_reports_signal_activation_counts(): void
    {
        Restaurant::factory()->create([
            'google_rating' => 4.5,
            'social_links_count' => 3,
            'website_clicks_count' => 5,
            'pageviews_count' => 1,
            'has_award' => true,
            'popularity_score' => 0.9,
        ]);
        Restaurant::factory()->freeOnly()->create([
            'google_rating' => null,
            'social_links_count' => 0,
            'website_clicks_count' => 0,
            'pageviews_count' => 0,
            'has_award' => false,
            'popularity_score' => 0.1,
        ]);

        Artisan::call('ranking:audit');

        $output = Artisan::output();

        $this->assertStringContainsString('Signal activation', $output);
        $this->assertMatchesRegularExpression('/quality\s+1\s+50\.0%/s', $output);
        $this->assertMatchesRegularExpression('/social_links_count\s+1\s+50\.0%/s', $output);
        $this->assertMatchesRegularExpression('/has_award\s+1\s+50\.0%/s', $output);
        $this->assertMatchesRegularExpression('/data_completeness\s+2\s+100\.0%/s', $output);
    }

    public function test_audit_reports_distribution_and_deciles(): void
    {
        Restaurant::factory()->create(['popularity_score' => 0.1]);
        Restaurant::factory()->create(['popularity_score' => 0.2]);
        Restaurant::factory()->create(['popularity_score' => 0.3]);

        Artisan::call('ranking:audit');

        $output = Artisan::output();

        $this->assertStringContainsString('Score distribution', $output);
        $this->assertStringContainsString('min 0.1000', $output);
        $this->assertStringContainsString('max 0.3000', $output);
        $this->assertStringContainsString('mean 0.2000', $output);
        $this->assertStringContainsString('median 0.2000', $output);
        $this->assertStringContainsString('Score deciles', $output);
        $this->assertStringContainsString('p50  0.2000', $output);
    }

    public function test_audit_reports_cohort_overlap(): void
    {
        Restaurant::factory()->create([
            'google_rating' => 4.5,
            'google_review_count' => 500,
            'popularity_score' => 0.50,
        ]);
        Restaurant::factory()->create([
            'google_rating' => 4.7,
            'google_review_count' => 600,
            'popularity_score' => 0.60,
        ]);
        Restaurant::factory()->freeOnly()->create([
            'google_rating' => null,
            'popularity_score' => 0.30,
        ]);
        Restaurant::factory()->freeOnly()->create([
            'google_rating' => null,
            'popularity_score' => 0.52,
        ]);

        Artisan::call('ranking:audit');

        $output = Artisan::output();

        $this->assertStringContainsString('Cohort overlap', $output);
        $this->assertMatchesRegularExpression('/rated min\s+0\.5000\s+unrated max\s+0\.5200/', $output);
        $this->assertMatchesRegularExpression('/unrated above rated min:\s+1 \(50\.0% of unrated\)/', $output);
        $this->assertMatchesRegularExpression('/rated below unrated max:\s+1 \(50\.0% of rated\)/', $output);
    }

    public function test_audit_skips_cohort_overlap_when_one_cohort_empty(): void
    {
        Restaurant::factory()->create(['google_rating' => 4.5, 'popularity_score' => 0.60]);
        Restaurant::factory()->create(['google_rating' => 4.6, 'popularity_score' => 0.70]);

        Artisan::call('ranking:audit');

        $output = Artisan::output();

        $this->assertStringContainsString('Cohort overlap', $output);
        $this->assertStringContainsString('need both rated and unrated rows', $output);
    }

    public function test_audit_reports_zero_rows_gracefully(): void
    {
        $exitCode = Artisan::call('ranking:audit');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No restaurants found to audit.', Artisan::output());
    }

    public function test_audit_recompute_uses_forecast_not_persisted_scores(): void
    {
        // A name-only row: persisted score is inflated (0.999) but a recompute
        // under the current weights collapses it to name-only completeness
        // (1/10 = 0.1, weight 0.05) diluted by the six always-active
        // engagement signals at 0.0 (weight 0.50 combined): (0.05*0.1)/0.55.
        Restaurant::factory()->create([
            'name' => 'Name Only',
            'address' => null,
            'phone' => null,
            'latitude' => null,
            'longitude' => null,
            'price_range' => null,
            'website_url' => null,
            'photo_url' => null,
            'features' => null,
            'social_links_count' => 0,
            'google_rating' => null,
            'google_review_count' => 0,
            'has_award' => false,
            'popularity_score' => 0.999,
        ]);

        Artisan::call('ranking:audit');
        $persisted = Artisan::output();
        $this->assertStringContainsString('persisted popularity_score', $persisted);
        $this->assertStringContainsString('0.999', $persisted);

        Artisan::call('ranking:audit', ['--recompute' => true]);
        $forecast = Artisan::output();
        $this->assertStringContainsString('Recompute mode', $forecast);
        $this->assertStringContainsString('recomputed under current weights', $forecast);
        $this->assertStringNotContainsString('0.999', $forecast);
        $this->assertStringContainsString('0.0091', $forecast);
    }
}
