<?php

namespace Tests\Feature;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * restaurants:photo-junk — report-first, reversible removal of platform logo
 * sprites (Instagram/Facebook rsrc.php) stored as a restaurant photo.
 *
 * The sprite is quarantined through FieldQuarantineService (never deleted), so
 * `restaurants:integrity --restore=photo_platform_asset` puts it back.
 */
class PhotoJunkCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SPRITE = 'https://static.cdninstagram.com/rsrc.php/v4/yD/r/R0fBIMurK8v.png';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function junkRow(array $overrides = []): Restaurant
    {
        return Restaurant::factory()->create(array_merge([
            'photo_url' => self::SPRITE,
            'photos' => [self::SPRITE, 'https://cdn.example.com/good.jpg'],
            'photo_thumb' => '1-abcdef0123.webp',
        ], $overrides));
    }

    public function test_report_only_changes_nothing(): void
    {
        $row = $this->junkRow();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-junk');
        $command->expectsOutputToContain('DRY RUN')->assertSuccessful()->run();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(self::SPRITE, $fresh->photo_url);
        $this->assertContains(self::SPRITE, $fresh->photos ?? []);
        $this->assertSame('1-abcdef0123.webp', $fresh->photo_thumb);
        $this->assertSame(0, FieldQuarantine::query()->count());
    }

    public function test_apply_quarantines_the_photo_and_strips_the_gallery(): void
    {
        $row = $this->junkRow();

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-junk', ['--apply' => true]);
        $command->expectsOutputToContain('APPLIED')->assertSuccessful()->run();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->photo_url);
        $this->assertSame(['https://cdn.example.com/good.jpg'], $fresh->photos);
        $this->assertNull($fresh->photo_thumb, 'the derived thumbnail must be cleared with the photo');

        $entry = FieldQuarantine::query()
            ->where('restaurant_id', $row->id)
            ->where('field', 'photo_url')
            ->whereNull('restored_at')
            ->first();
        $this->assertNotNull($entry);
        $this->assertSame(self::SPRITE, $entry->old_value);
        $this->assertSame('photo_platform_asset', $entry->reason);
    }

    public function test_apply_clears_the_gallery_when_every_entry_is_junk(): void
    {
        $row = $this->junkRow([
            'photo_url' => 'https://cdn.example.com/real.jpg',
            'photos' => [self::SPRITE],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-junk', ['--apply' => true]);
        $command->assertSuccessful()->run();

        $fresh = $row->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('https://cdn.example.com/real.jpg', $fresh->photo_url, 'a real photo_url is untouched');
        $this->assertNull($fresh->photos);
    }

    public function test_apply_ignores_clean_rows(): void
    {
        $row = Restaurant::factory()->create([
            'photo_url' => 'https://cdn.example.com/real.jpg',
            'photos' => ['https://cdn.example.com/real.jpg'],
        ]);

        /** @var PendingCommand $command */
        $command = $this->artisan('restaurants:photo-junk', ['--apply' => true]);
        $command->assertSuccessful()->run();

        $this->assertSame('https://cdn.example.com/real.jpg', $row->fresh()?->photo_url);
        $this->assertSame(0, FieldQuarantine::query()->count());
    }

    public function test_a_quarantined_photo_is_restorable(): void
    {
        $row = $this->junkRow();

        /** @var PendingCommand $apply */
        $apply = $this->artisan('restaurants:photo-junk', ['--apply' => true]);
        $apply->assertSuccessful()->run();
        $this->assertNull($row->fresh()?->photo_url);

        /** @var PendingCommand $restore */
        $restore = $this->artisan('restaurants:integrity', ['--restore' => 'photo_platform_asset']);
        $restore->assertSuccessful()->run();

        $this->assertSame(self::SPRITE, $row->fresh()?->photo_url);
    }
}
