<?php

namespace Tests\Feature;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Services\WikimediaPhotoAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Tests\TestCase;

/**
 * restaurants:wikimedia-photo-audit — report-only classification of Wikimedia
 * photos. The command must never write: it only counts verdicts and prints
 * unverified examples for the operator to review.
 */
class WikimediaPhotoAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function row(string $photo, array $overrides = []): Restaurant
    {
        return Restaurant::factory()->create(array_merge([
            'photo_url' => $photo,
            'photo_source' => 'wikimedia',
            'latitude' => 30.25,
            'longitude' => -97.75,
        ], $overrides));
    }

    /**
     * @param  array<int, string>  $verdicts  in row-id order
     */
    private function fakeAuditor(array $verdicts): void
    {
        $queue = $verdicts;
        $auditor = Mockery::mock(WikimediaPhotoAuditor::class);
        $auditor->shouldReceive('audit')->andReturnUsing(function () use (&$queue): array {
            return ['verdict' => array_shift($queue) ?? WikimediaPhotoAuditor::VERDICT_UNVERIFIED, 'distance_m' => null, 'title' => null];
        });
        $this->app->instance(WikimediaPhotoAuditor::class, $auditor);
    }

    public function test_report_only_counts_verdicts_and_never_writes(): void
    {
        $rows = collect([
            $this->row('https://upload.wikimedia.org/a.jpg'),
            $this->row('https://upload.wikimedia.org/b.jpg'),
            $this->row('https://upload.wikimedia.org/c.jpg'),
            $this->row('https://upload.wikimedia.org/d.jpg'),
        ]);

        $this->fakeAuditor([
            WikimediaPhotoAuditor::VERDICT_COMMONS,
            WikimediaPhotoAuditor::VERDICT_UNVERIFIED,
            WikimediaPhotoAuditor::VERDICT_UNVERIFIED,
            WikimediaPhotoAuditor::VERDICT_UNCHECKABLE,
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:wikimedia-photo-audit', ['--sample' => 2]);
        $command->expectsOutputToContain('REPORT ONLY')
            ->expectsOutputToContain('Audited: 4')
            ->expectsOutputToContain('verified (Commons geotag): 1')
            ->expectsOutputToContain('unverified: 2')
            ->expectsOutputToContain('uncheckable: 1')
            ->assertSuccessful()->run();

        foreach ($rows as $row) {
            $this->assertNotNull($row->fresh()?->photo_url, 'the audit must not clear photos');
        }
    }

    public function test_source_filter_limits_the_audited_rows(): void
    {
        $this->row('https://upload.wikimedia.org/keep.jpg');
        $this->row('https://upload.wikimedia.org/skip.jpg', ['photo_source' => 'website']);

        $this->fakeAuditor([WikimediaPhotoAuditor::VERDICT_UNVERIFIED]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:wikimedia-photo-audit', ['--source' => 'wikimedia']);
        $command->expectsOutputToContain('Audited: 1')->assertSuccessful()->run();
    }

    public function test_apply_quarantines_unverified_photos_reversibly(): void
    {
        $junk = 'https://upload.wikimedia.org/junk.jpg';
        $row = $this->row($junk, [
            'photos' => [$junk, 'https://cdn.example.com/real.jpg'],
            'photo_thumb' => '1-abcdef0123.webp',
        ]);

        $this->fakeAuditor([WikimediaPhotoAuditor::VERDICT_UNVERIFIED]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:wikimedia-photo-audit', ['--apply' => true]);
        $command->expectsOutputToContain('APPLIED')->assertSuccessful()->run();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->photo_url);
        $this->assertSame(['https://cdn.example.com/real.jpg'], $fresh->photos);
        $this->assertNull($fresh->photo_thumb);

        $entry = FieldQuarantine::query()
            ->where('restaurant_id', $row->id)
            ->where('field', 'photo_url')
            ->whereNull('restored_at')
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame($junk, $entry->old_value);
        $this->assertSame('wikimedia_name_only_match', $entry->reason);

        /** @var PendingCommand $restore */
        $restore = $this->artisan('restaurants:integrity', ['--restore' => 'wikimedia_name_only_match']);
        $restore->assertSuccessful()->run();
        $this->assertSame($junk, $row->fresh()?->photo_url);
    }

    public function test_apply_leaves_verified_and_uncheckable_rows_alone(): void
    {
        $verified = $this->row('https://upload.wikimedia.org/verified.jpg');
        $uncheckable = $this->row('https://upload.wikimedia.org/unknown.jpg', ['latitude' => null, 'longitude' => null]);

        $this->fakeAuditor([
            WikimediaPhotoAuditor::VERDICT_COMMONS,
            WikimediaPhotoAuditor::VERDICT_UNCHECKABLE,
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:wikimedia-photo-audit', ['--apply' => true]);
        $command->assertSuccessful()->run();

        $this->assertNotNull($verified->fresh()?->photo_url);
        $this->assertNotNull($uncheckable->fresh()?->photo_url);
        $this->assertSame(0, FieldQuarantine::query()->count());
    }
}
