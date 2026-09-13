<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\FieldQuarantineService;
use App\Support\PhotoUrl;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Remove platform logo sprites stored as restaurant photos.
 *
 * Around 3,800 prod rows had Instagram's 760 KB logo sprite
 * (static.cdninstagram.com/rsrc.php/…/R0fBIMurK8v.png) as their photo_url,
 * rendered at 96–176 px; the galleries carry it too. Report-only by default.
 * With --apply, photo_url is quarantined through FieldQuarantineService (never
 * deleted — reversible with `restaurants:integrity
 * --restore=photo_platform_asset`) and the sprite is stripped from photos.
 * The derived photo_thumb is cleared with the photo. Take a prod DB backup
 * before --apply.
 */
class PhotoJunk extends Command
{
    protected $signature = 'restaurants:photo-junk
        {--apply : Quarantine platform-asset photos and strip them from galleries (default: report only)}
        {--limit=0 : Max restaurants to process (0 = all)}
        {--sample=5 : Example rows printed}';

    protected $description = 'Remove platform logo sprites (Instagram/Facebook rsrc.php) stored as restaurant photos';

    private const REASON = 'photo_platform_asset';

    private const DETECTOR = 'restaurants:photo-junk';

    public function handle(FieldQuarantineService $quarantine): int
    {
        $apply = (bool) $this->option('apply');
        $sample = max(0, (int) $this->option('sample'));
        $limit = (int) $this->option('limit');
        $cap = $limit > 0 ? $limit : PHP_INT_MAX;

        $rows = 0;
        $urls = 0;
        $galleries = 0;
        $thumbsCleared = 0;
        $processed = 0;
        $examples = [];

        Restaurant::query()->active()
            ->where(function ($query): void {
                $query->where('photo_url', 'like', '%rsrc.php%')
                    ->orWhere('photos', 'like', '%rsrc.php%');
            })
            ->chunkById(500, function (Collection $restaurants) use ($quarantine, $apply, $sample, $cap, &$rows, &$urls, &$galleries, &$thumbsCleared, &$processed, &$examples): bool {
                foreach ($restaurants as $restaurant) {
                    if ($processed >= $cap) {
                        return false;
                    }

                    $photoUrl = (string) ($restaurant->photo_url ?? '');
                    $urlIsJunk = PhotoUrl::isPlatformAsset($photoUrl);

                    $photos = is_array($restaurant->photos) ? $restaurant->photos : [];
                    $cleanPhotos = array_values(array_filter(
                        $photos,
                        fn ($photo): bool => ! is_string($photo) || ! PhotoUrl::isPlatformAsset($photo),
                    ));
                    $galleryIsJunk = count($cleanPhotos) < count($photos);

                    if (! $urlIsJunk && ! $galleryIsJunk) {
                        continue;
                    }

                    $processed++;
                    $rows++;
                    $urls += $urlIsJunk ? 1 : 0;
                    $galleries += $galleryIsJunk ? 1 : 0;

                    if (count($examples) < $sample) {
                        $examples[] = "{$restaurant->name} ({$restaurant->city}, {$restaurant->state})";
                    }

                    if (! $apply) {
                        continue;
                    }

                    if ($urlIsJunk) {
                        $quarantine->quarantineFields($restaurant, ['photo_url'], self::REASON, self::DETECTOR, ['url' => $photoUrl]);

                        // The thumbnail embeds the photo hash, so it is already
                        // unservable; clear the column so it is regenerated if a
                        // real photo later replaces the sprite.
                        if (! empty($restaurant->photo_thumb)) {
                            Restaurant::query()->whereKey($restaurant->id)->update(['photo_thumb' => null]);
                            $restaurant->forceFill(['photo_thumb' => null])->syncOriginal();
                            $thumbsCleared++;
                        }
                    }

                    if ($galleryIsJunk) {
                        if ($cleanPhotos === []) {
                            $quarantine->quarantineFields($restaurant, ['photos'], self::REASON, self::DETECTOR);
                        } else {
                            $quarantine->replaceFields(
                                $restaurant,
                                ['photos' => json_encode($cleanPhotos, JSON_THROW_ON_ERROR)],
                                self::REASON,
                                self::DETECTOR,
                                ['stripped' => count($photos) - count($cleanPhotos)],
                            );
                        }
                    }
                }

                return true;
            });

        $this->newLine();
        $this->line('Mode: '.($apply ? '<fg=green>APPLIED (junk photos quarantined)</>' : '<fg=yellow>DRY RUN (no changes persisted)</>'));
        $this->line("Flagged rows: {$rows}");
        $this->line("photo_url containing the sprite: {$urls}");
        $this->line("galleries containing the sprite: {$galleries}");
        if ($apply) {
            $this->line("photo_thumb cleared: {$thumbsCleared}");
        }
        foreach ($examples as $example) {
            $this->line('   · '.$example);
        }

        Log::channel('enrichment')->info('Photo junk sweep complete', [
            'mode' => $apply ? 'applied' : 'dry-run',
            'rows' => $rows,
            'photo_urls' => $urls,
            'galleries' => $galleries,
            'thumbs_cleared' => $thumbsCleared,
        ]);

        return self::SUCCESS;
    }
}
