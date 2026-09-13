<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\FieldQuarantineService;
use App\Services\WikimediaPhotoAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Audit Wikimedia photos: how many are a real picture of the venue (Commons
 * file geotagged near the pin, or a nearby Wikidata item's P18) versus a
 * name-only match ("Lost & Found" the bin, "Ela" the person).
 *
 * Report-only by default. With --apply, unverified `photo_url`s are
 * quarantined through FieldQuarantineService (never deleted; reversible with
 * `restaurants:integrity --restore=wikimedia_name_only_match`), stripped from
 * the gallery, and their derived photo_thumb cleared. Take a prod DB backup
 * before --apply. Verified and uncheckable photos are never touched.
 */
class WikimediaPhotoAudit extends Command
{
    protected $signature = 'restaurants:wikimedia-photo-audit
        {--limit=0 : Max restaurants to audit (0 = all)}
        {--sample=10 : Unverified examples to print}
        {--source= : Only rows whose photo_source matches (default: any)}
        {--apply : Quarantine unverified photos (default: report only)}';

    protected $description = 'Report or quarantine Wikimedia photos that are name-only matches, not pictures of the venue';

    private const REASON = 'wikimedia_name_only_match';

    private const DETECTOR = 'restaurants:wikimedia-photo-audit';

    public function handle(WikimediaPhotoAuditor $auditor, FieldQuarantineService $quarantine): int
    {
        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');
        $sample = max(0, (int) $this->option('sample'));
        $source = $this->option('source');

        $query = Restaurant::query()
            ->active()
            ->whereNotNull('photo_url')
            ->where('photo_url', '!=', '')
            ->where('photo_url', 'like', '%wikimedia.org%');

        if (is_string($source) && $source !== '') {
            $query->where('photo_source', $source);
        }

        $query->orderByRaw('COALESCE(popularity_score, 0) DESC')->orderBy('id');

        $this->info($apply
            ? 'APPLIED — unverified photos are moved to field_quarantine (reversible).'
            : 'REPORT ONLY — nothing is written. Re-run with --apply to quarantine.');
        if ($source !== null && $source !== '') {
            $this->line("Filtering to photo_source = {$source}");
        }

        $counts = [
            WikimediaPhotoAuditor::VERDICT_COMMONS => 0,
            WikimediaPhotoAuditor::VERDICT_WIKIDATA => 0,
            WikimediaPhotoAuditor::VERDICT_UNVERIFIED => 0,
            WikimediaPhotoAuditor::VERDICT_UNCHECKABLE => 0,
        ];

        $total = 0;
        $quarantined = 0;
        $galleries = 0;
        $thumbsCleared = 0;
        $examples = [];

        $rows = $limit > 0 ? $query->limit($limit)->get() : $query->lazy();

        foreach ($rows as $restaurant) {
            $result = $auditor->audit($restaurant);
            $verdict = $result['verdict'];
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
            $total++;

            if ($verdict === WikimediaPhotoAuditor::VERDICT_UNVERIFIED && count($examples) < $sample) {
                $examples[] = "{$restaurant->name} ({$restaurant->city}, {$restaurant->state}) — {$restaurant->photo_url}";
            }

            if (! $apply || $verdict !== WikimediaPhotoAuditor::VERDICT_UNVERIFIED) {
                continue;
            }

            $photoUrl = (string) $restaurant->photo_url;

            $quarantine->quarantineFields($restaurant, ['photo_url'], self::REASON, self::DETECTOR, array_filter([
                'title' => $result['title'],
            ]));
            $quarantined++;

            $photos = is_array($restaurant->photos) ? $restaurant->photos : [];
            $cleanPhotos = array_values(array_filter($photos, fn ($photo): bool => $photo !== $photoUrl));
            if (count($cleanPhotos) < count($photos)) {
                $galleries++;
                if ($cleanPhotos === []) {
                    $quarantine->quarantineFields($restaurant, ['photos'], self::REASON, self::DETECTOR);
                } else {
                    $quarantine->replaceFields($restaurant, ['photos' => json_encode($cleanPhotos, JSON_THROW_ON_ERROR)], self::REASON, self::DETECTOR);
                }
            }

            // The thumb embeds the photo hash, so it is already unservable.
            if (! empty($restaurant->photo_thumb)) {
                Restaurant::query()->whereKey($restaurant->id)->update(['photo_thumb' => null]);
                $restaurant->forceFill(['photo_thumb' => null])->syncOriginal();
                $thumbsCleared++;
            }
        }

        $this->newLine();
        $this->line("Audited: {$total}");
        $this->line('verified (Commons geotag): '.$counts[WikimediaPhotoAuditor::VERDICT_COMMONS]);
        $this->line('verified (Wikidata P18): '.$counts[WikimediaPhotoAuditor::VERDICT_WIKIDATA]);
        $this->line('unverified: '.$counts[WikimediaPhotoAuditor::VERDICT_UNVERIFIED]);
        $this->line('uncheckable: '.$counts[WikimediaPhotoAuditor::VERDICT_UNCHECKABLE]);
        if ($apply) {
            $this->line("quarantined: {$quarantined}");
            $this->line("galleries stripped: {$galleries}");
            $this->line("photo_thumb cleared: {$thumbsCleared}");
        }

        if ($examples !== []) {
            $this->newLine();
            $this->line('Unverified examples:');
            foreach ($examples as $example) {
                $this->line('   · '.$example);
            }
        }

        Log::channel('enrichment')->info('Wikimedia photo audit complete', array_merge(
            ['mode' => $apply ? 'applied' : 'report', 'source' => $source ?: null, 'audited' => $total, 'quarantined' => $quarantined],
            $counts,
        ));

        return self::SUCCESS;
    }
}
