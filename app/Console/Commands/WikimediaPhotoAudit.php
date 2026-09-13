<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\WikimediaPhotoAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Report-only audit of Wikimedia photos: how many are a real picture of the
 * venue (Commons file geotagged near the pin, or a nearby Wikidata item's P18)
 * versus a name-only match ("Lost & Found" the bin, "Ela" the person).
 *
 * Never writes. Removal is a separate, operator-approved decision — do not
 * strip photos in bulk without that OK (backlog goal 19).
 */
class WikimediaPhotoAudit extends Command
{
    protected $signature = 'restaurants:wikimedia-photo-audit
        {--limit=0 : Max restaurants to audit (0 = all)}
        {--sample=10 : Unverified examples to print}
        {--source= : Only rows whose photo_source matches (default: any)}';

    protected $description = 'Report how many Wikimedia photos are verified by coordinates vs name-only matches';

    public function handle(WikimediaPhotoAuditor $auditor): int
    {
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

        $this->info('REPORT ONLY — nothing is written. Use the results to decide what to fix.');
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
        }

        $this->newLine();
        $this->line("Audited: {$total}");
        $this->line('verified (Commons geotag): '.$counts[WikimediaPhotoAuditor::VERDICT_COMMONS]);
        $this->line('verified (Wikidata P18): '.$counts[WikimediaPhotoAuditor::VERDICT_WIKIDATA]);
        $this->line('unverified: '.$counts[WikimediaPhotoAuditor::VERDICT_UNVERIFIED]);
        $this->line('uncheckable: '.$counts[WikimediaPhotoAuditor::VERDICT_UNCHECKABLE]);

        if ($examples !== []) {
            $this->newLine();
            $this->line('Unverified examples:');
            foreach ($examples as $example) {
                $this->line('   · '.$example);
            }
        }

        Log::channel('enrichment')->info('Wikimedia photo audit complete', array_merge(
            ['source' => $source ?: null, 'audited' => $total],
            $counts,
        ));

        return self::SUCCESS;
    }
}
