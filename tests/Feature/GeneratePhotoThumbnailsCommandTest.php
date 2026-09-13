<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use App\Services\PhotoThumbnailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Tests\TestCase;

/**
 * Contract for restaurants:photo-thumbnails: pick active photo rows without a
 * fresh thumbnail, in popularity order, bounded by --limit; dry-run by default.
 * The service is mocked here so these tests never touch the network or GD —
 * PhotoThumbnailServiceTest covers the download/resize pipeline.
 */
class GeneratePhotoThumbnailsCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a service whose hasThumb() is driven by the given callback.
     *
     * @param  callable(Restaurant): bool  $hasThumb
     */
    private function mockService(callable $hasThumb): Mockery\MockInterface
    {
        $service = Mockery::mock(PhotoThumbnailService::class);
        $service->shouldReceive('hasThumb')->andReturnUsing($hasThumb);
        $this->app->instance(PhotoThumbnailService::class, $service);

        return $service;
    }

    private function allowGenerate(Mockery\MockInterface $service): void
    {
        $service->shouldReceive('generate')->andReturnUsing(
            fn (Restaurant $r): string => $r->id.'-'.substr(sha1((string) $r->photo_url), 0, 10).'.webp'
        );
    }

    public function test_dry_run_generates_nothing(): void
    {
        $rows = Restaurant::factory()->count(2)->create(['photo_url' => 'https://a.example/one.jpg']);
        $service = $this->mockService(fn (): bool => false);
        $service->shouldNotReceive('generate');

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-thumbnails');
        $command->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Would generate: 2');
        $command->assertSuccessful()->run();

        foreach ($rows as $row) {
            $fresh = $row->fresh();
            $this->assertInstanceOf(Restaurant::class, $fresh);
            $this->assertNull($fresh->photo_thumb);
        }
    }

    public function test_apply_stores_a_thumbnail_per_row(): void
    {
        Restaurant::factory()->create(['photo_url' => 'https://a.example/one.jpg']);
        $service = $this->mockService(fn (): bool => false);
        $this->allowGenerate($service);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-thumbnails', ['--apply' => true]);
        $command->expectsOutputToContain('APPLIED')->expectsOutputToContain('Generated: 1');
        $command->assertSuccessful()->run();

        $this->assertNotNull(Restaurant::query()->firstOrFail()->photo_thumb);
    }

    public function test_rows_with_a_fresh_thumbnail_are_skipped(): void
    {
        $row = Restaurant::factory()->create([
            'id' => 7,
            'photo_url' => 'https://a.example/one.jpg',
            'photo_thumb' => '7-'.substr(sha1('https://a.example/one.jpg'), 0, 10).'.webp',
        ]);
        $service = $this->mockService(fn (Restaurant $r): bool => $r->id === $row->id);
        $service->shouldNotReceive('generate');

        // Without --refresh the SQL filter excludes rows that already have a
        // thumb; with --refresh they are scanned and then skipped by hasThumb().
        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-thumbnails', ['--apply' => true, '--refresh' => true]);
        $command->expectsOutputToContain('Generated: 0')
            ->expectsOutputToContain('Skipped (already have a fresh thumb): 1');
        $command->assertSuccessful()->run();
    }

    public function test_limit_bounds_the_number_of_thumbnails_generated(): void
    {
        Restaurant::factory()->count(3)->create(['photo_url' => 'https://a.example/one.jpg']);
        $service = $this->mockService(fn (): bool => false);
        $this->allowGenerate($service);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-thumbnails', ['--apply' => true, '--limit' => 2]);
        $command->expectsOutputToContain('Generated: 2');
        $command->assertSuccessful()->run();

        $this->assertSame(2, Restaurant::query()->whereNotNull('photo_thumb')->count());
    }

    public function test_stale_thumbnails_are_only_reprocessed_with_refresh(): void
    {
        Restaurant::factory()->create([
            'photo_url' => 'https://a.example/new.jpg',
            'photo_thumb' => '9-'.substr(sha1('https://a.example/old.jpg'), 0, 10).'.webp',
        ]);

        $service = $this->mockService(fn (): bool => false);
        $this->allowGenerate($service);

        /** @var PendingCommand $first */
        $first = $this->artisan('restaurants:photo-thumbnails', ['--apply' => true]);
        $first->expectsOutputToContain('Generated: 0');
        $first->assertSuccessful()->run();

        /** @var PendingCommand $second */
        $second = $this->artisan('restaurants:photo-thumbnails', ['--apply' => true, '--refresh' => true]);
        $second->expectsOutputToContain('Generated: 1');
        $second->assertSuccessful()->run();
    }
}
