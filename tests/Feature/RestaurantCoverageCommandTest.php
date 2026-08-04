<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RestaurantCoverageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_coverage_reports_field_counts_and_percentages(): void
    {
        Restaurant::factory()->create([
            'name' => 'Full Profile',
            'website_url' => 'https://example.com',
            'phone' => '555-0100',
            'opening_hours' => ['Mon' => '9am-5pm'],
            'price_range' => '$$',
            'description' => 'A full profile.',
            'google_rating' => 4.5,
        ]);

        Restaurant::factory()->create([
            'name' => 'Sparse Profile',
            'website_url' => '',
            'phone' => null,
            'opening_hours' => null,
            'price_range' => null,
            'description' => null,
            'google_rating' => null,
        ]);

        Artisan::call('restaurants:coverage');

        $output = Artisan::output();

        $this->assertStringContainsString('Restaurant Field Coverage (2 total)', $output);
        $this->assertStringContainsString('AI fillable gaps', $output);
        $this->assertStringContainsString('1 of 2 restaurants', $output);
        $this->assertMatchesRegularExpression('/website_url\s+1\s+50\.0%/s', $output);
        $this->assertMatchesRegularExpression('/phone\s+1\s+50\.0%/s', $output);
        $this->assertMatchesRegularExpression('/description\s+1\s+50\.0%/s', $output);
        $this->assertMatchesRegularExpression('/google_rating\s+1\s+50\.0%/s', $output);
    }

    public function test_coverage_counts_social_links_as_populated(): void
    {
        Restaurant::factory()->create(['social_links_count' => 3]);
        Restaurant::factory()->create(['social_links_count' => 0]);

        Artisan::call('restaurants:coverage');

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/social_links\s+1\s+50\.0%/s', $output);
    }
}
