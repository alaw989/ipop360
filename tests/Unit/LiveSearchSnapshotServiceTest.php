<?php

namespace Tests\Unit;

use App\Models\ExternalApiCache;
use App\Services\LiveSearchSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveSearchSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    private LiveSearchSnapshotService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'restaurant-finder.live_search.page_snapshot_minutes' => 10,
            'restaurant-finder.cache.preview_snapshot_days' => 7,
        ]);

        $this->service = new LiveSearchSnapshotService;
    }

    public function test_read_page_snapshot_returns_empty_array_on_miss(): void
    {
        $this->assertSame([], $this->service->readPageSnapshot('union_page:missing'));
    }

    public function test_page_snapshot_round_trips_through_cache(): void
    {
        $results = [
            ['name' => 'Venue A', 'slug' => 'venue-a'],
            ['name' => 'Venue B', 'slug' => 'venue-b'],
        ];

        $this->service->storePageSnapshot('browse_page:test', $results);

        $this->assertSame($results, $this->service->readPageSnapshot('browse_page:test'));
    }

    public function test_page_snapshot_stores_at_configured_minutes_ttl(): void
    {
        $this->service->storePageSnapshot('union_page:test', [['name' => 'Venue']]);

        $record = ExternalApiCache::where('external_id', 'union_page:test')->first();
        $this->assertNotNull($record);
        $this->assertSame('union_page', $record->source);
        $this->assertSame(now()->addMinutes(10)->timestamp, $record->expires_at->timestamp);
    }

    public function test_store_previews_writes_each_venue_under_preview_key(): void
    {
        $this->service->storePreviews([
            ['name' => 'Alpha', 'slug' => 'alpha-aaaaaa'],
            ['name' => 'Beta', 'slug' => 'beta-bbbbbb'],
            ['name' => 'No Slug'], // venue without a slug is skipped
        ]);

        $this->assertNotNull(ExternalApiCache::where('external_id', 'preview:alpha-aaaaaa')->first());
        $this->assertNotNull(ExternalApiCache::where('external_id', 'preview:beta-bbbbbb')->first());
        $this->assertSame(2, ExternalApiCache::where('source', 'preview')->count());
    }

    public function test_store_previews_is_noop_for_empty_results(): void
    {
        $this->service->storePreviews([]);

        $this->assertSame(0, ExternalApiCache::count());
    }

    public function test_preview_round_trips_through_cache(): void
    {
        $venue = ['id' => -123, 'name' => 'Lickin Good Donuts', 'slug' => 'lickin-good-donuts'];

        $this->service->storePreview('lickin-good-donuts', $venue);

        $this->assertSame($venue, $this->service->readPreview('lickin-good-donuts'));
    }

    public function test_read_preview_returns_null_on_miss(): void
    {
        $this->assertNull($this->service->readPreview('missing-slug'));
    }

    public function test_preview_stores_at_configured_days_ttl(): void
    {
        $this->service->storePreview('slug-1', ['name' => 'Venue']);

        $record = ExternalApiCache::where('external_id', 'preview:slug-1')->first();
        $this->assertNotNull($record);
        $this->assertSame('preview', $record->source);
        $this->assertSame(now()->addDays(7)->timestamp, $record->expires_at->timestamp);
    }
}
