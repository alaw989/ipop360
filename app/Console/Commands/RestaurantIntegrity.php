<?php

namespace App\Console\Commands;

use App\Models\FieldQuarantine;
use App\Models\Restaurant;
use App\Models\RestaurantSocialLink;
use App\Services\FieldQuarantineService;
use App\Services\RestaurantWebsiteScraperService;
use App\Services\SocialLinkRecorder;
use App\Services\WebsiteIdentityVerifier;
use App\Support\AddressParts;
use App\Support\AreaCodeStates;
use App\Support\PlaceLocation;
use App\Support\SocialProfileUrl;
use App\Support\StateAbbreviations;
use App\Support\ZipLocation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Data-integrity scorecard + reversible cleanup of data that is provably
 * wrong (data-integrity phase 2).
 *
 * Report-only by default. With --apply, every flagged value is moved to
 * field_quarantine (FieldQuarantineService) — never deleted — and
 * `--restore=<reason>` puts a whole detector's removals back. Detectors are
 * deterministic and need no network (except verifying social profiles
 * recovered from website fields):
 *
 *   website_blocked      website_url on a reference/aggregator/social/parking
 *                        host or a reference path (dictionary, IMDb title…).
 *                        A social profile stored as the website is moved to
 *                        social links instead. Photos taken from that site and
 *                        social links scraped from it go with it.
 *   social_junk          social links that are not real profiles (namespace
 *                        URIs, the Meta Pixel, share endpoints…); valid ones
 *                        are rewritten to their canonical URL.
 *   social_brand         a profile URL shared by >= social_brand_min_restaurants
 *                        restaurants is scoped 'brand' (kept, never scored).
 *   copied_rating        the same name + identical rating + review count in
 *                        two or more cities: copies made by the old name-only
 *                        cache backfill. Kept only on the one member whose own
 *                        phone area code matches its state; otherwise removed
 *                        from all (SerpApi re-rates the true venue).
 *   copied_phone         the same name + phone in two or more cities: the
 *                        phone stays only on members in the area code's state.
 *   address_other_state  an address naming a different state than the row,
 *                        unless its ZIP is at the pin (then the row's city and
 *                        state are the wrong part: city_far_from_location).
 *   address_far_from_location
 *                        an address whose ZIP is a different metro from the
 *                        pin (> address_zip_unmatched_far_km), on a row with no
 *                        Overture match: copied from a same-named venue
 *                        elsewhere. Overture-matched rows are left to
 *                        overture:import, which replaces the address with the
 *                        place's. A pin sitting on a city center (a geocoding
 *                        fallback) is skipped: there the pin may be the wrong part.
 *   city_far_from_location
 *                        a city that isn't where the pin is: the search grid's
 *                        name on a venue in the next town (Novi stored as "Ann
 *                        Arbor"), or a city copied from a same-named venue
 *                        elsewhere (Farzi NYC stored as "Anchorage, AK"). It is
 *                        corrected from the row's own address when that address
 *                        is the pin's (its ZIP is near the pin) and names a
 *                        place at the pin. An address address_other_state
 *                        removed for disagreeing with the wrong city comes back
 *                        as that evidence. With no evidence the city and state
 *                        are removed only when the city is a place in the row's
 *                        state over city_unmatched_far_km away. A grid label
 *                        with its state glued on ("Washington Dc") becomes the
 *                        place it names (reason city_grid_label).
 *   ai_guess             price_range/phone the AI model wrote (a guess), and
 *                        AI-written websites that never passed identity checks.
 */
class RestaurantIntegrity extends Command
{
    protected $signature = 'restaurants:integrity
        {--apply : Quarantine what the detectors flag (default: report only)}
        {--only=* : Run only these detectors}
        {--sample=5 : Example rows printed per detector}
        {--restore= : Restore every un-restored quarantine entry with this reason, then exit}';

    protected $description = 'Data-integrity scorecard; with --apply, reversibly quarantine provably wrong restaurant data';

    public const DETECTORS = [
        'website_blocked', 'social_junk', 'social_brand', 'copied_rating', 'copied_phone', 'address_other_state',
        'address_far_from_location', 'city_far_from_location', 'ai_guess',
    ];

    private const DETECTOR = 'restaurants:integrity';

    private bool $apply = false;

    private int $sample = 5;

    /** @var array<int, true> restaurants whose social_links_count must be re-derived */
    private array $touchedSocial = [];

    public function handle(
        FieldQuarantineService $quarantine,
        WebsiteIdentityVerifier $verifier,
        SocialLinkRecorder $recorder,
        RestaurantWebsiteScraperService $scraper,
    ): int {
        $restoreReason = $this->option('restore');
        if (is_string($restoreReason) && $restoreReason !== '') {
            return $this->restore($quarantine, $recorder, $restoreReason);
        }

        $this->apply = (bool) $this->option('apply');
        $this->sample = max(0, (int) $this->option('sample'));

        /** @var list<string> $only */
        $only = (array) $this->option('only');
        $unknown = array_diff($only, self::DETECTORS);
        if ($unknown !== []) {
            $this->error('Unknown detector(s): '.implode(', ', $unknown).'. Known: '.implode(', ', self::DETECTORS));

            return self::FAILURE;
        }
        $run = $only === [] ? self::DETECTORS : $only;

        $this->info($this->apply ? 'APPLY mode — flagged values are moved to field_quarantine (reversible).' : 'Report only — nothing is changed. Re-run with --apply to quarantine.');

        $results = [];
        foreach ($run as $detector) {
            $results[$detector] = match ($detector) {
                'website_blocked' => $this->websiteBlocked($quarantine, $verifier, $scraper),
                'social_junk' => $this->socialJunk($quarantine),
                'social_brand' => $this->socialBrand($recorder),
                'copied_rating' => $this->copiedRating($quarantine),
                'copied_phone' => $this->copiedPhone($quarantine),
                'address_other_state' => $this->addressOtherState($quarantine),
                'address_far_from_location' => $this->addressFarFromLocation($quarantine),
                'city_far_from_location' => $this->cityFarFromLocation($quarantine),
                'ai_guess' => $this->aiGuess($quarantine),
                default => throw new \LogicException("Unhandled detector '{$detector}'"),
            };
        }

        if ($this->apply && $this->touchedSocial !== []) {
            $recorder->recount(array_keys($this->touchedSocial));
        }

        $this->newLine();
        $this->table(['Detector', 'Rows flagged', 'Values quarantined'], array_map(
            fn (string $d) => [$d, $results[$d]['rows'], $this->apply ? $results[$d]['values'] : '(report only)'],
            array_keys($results)
        ));

        if ($this->apply) {
            $this->info('Done. Undo any detector with: php artisan restaurants:integrity --restore=<reason>');
            $this->info('Re-rank with: php artisan restaurants:score');
        }

        Log::channel('enrichment')->info('Data integrity run', [
            'apply' => $this->apply,
            'results' => array_map(fn (array $r) => ['rows' => $r['rows'], 'values' => $r['values']], $results),
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function websiteBlocked(FieldQuarantineService $quarantine, WebsiteIdentityVerifier $verifier, RestaurantWebsiteScraperService $scraper): array
    {
        $rows = 0;
        $values = 0;
        $examples = [];
        $reasons = [];

        Restaurant::query()->active()
            ->whereNotNull('website_url')->where('website_url', '!=', '')
            ->chunkById(1000, function (Collection $restaurants) use ($quarantine, $verifier, $scraper, &$rows, &$values, &$examples, &$reasons): void {
                foreach ($restaurants as $restaurant) {
                    $url = (string) $restaurant->website_url;
                    $social = SocialProfileUrl::canonicalize($url);

                    if ($social !== null) {
                        $reason = 'website_is_social_profile';
                    } elseif ($verifier->isBlockedUrl($url)) {
                        $reason = 'website_blocked_domain';
                    } elseif ($verifier->isReferenceUrl($url)) {
                        $reason = 'website_reference_url';
                    } else {
                        continue;
                    }

                    $rows++;
                    $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
                    if (count($examples) < $this->sample) {
                        $examples[] = "{$restaurant->name} ({$restaurant->city}) — {$url} [{$reason}]";
                    }
                    if (! $this->apply) {
                        continue;
                    }

                    // A venue's own Facebook/Instagram page is a correct link —
                    // just not a website. Keep it as a social link.
                    if ($social !== null && ! $restaurant->socialLinks()->where('platform', $social['platform'])->exists()) {
                        $verified = $scraper->verifyProfileUrl($social['url']);
                        $restaurant->socialLinks()->create([
                            'platform' => $social['platform'],
                            'url' => $social['url'],
                            'scope' => RestaurantSocialLink::SCOPE_LOCATION,
                            'verified_at' => $verified ? now() : null,
                            'last_check_failed_at' => $verified ? null : now(),
                        ]);
                        $this->touchedSocial[$restaurant->id] = true;
                    }

                    $values += $quarantine->quarantineFields($restaurant, ['website_url'], $reason, self::DETECTOR, ['url' => $url]);

                    if ($social === null) {
                        // Photos and socials scraped from someone else's site.
                        if ($restaurant->photo_source === 'website') {
                            $values += $quarantine->quarantineFields($restaurant, ['photo_url', 'photos'], 'photo_from_rejected_website', self::DETECTOR, ['website' => $url]);
                        }
                        foreach ($restaurant->socialLinks()->get() as $link) {
                            $quarantine->quarantineSocialLink($link, 'social_from_rejected_website', self::DETECTOR);
                            $values++;
                            $this->touchedSocial[$restaurant->id] = true;
                        }
                    }
                }
            });

        $this->report('website_blocked', $rows, $examples, $reasons);

        return ['rows' => $rows, 'values' => $values];
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function socialJunk(FieldQuarantineService $quarantine): array
    {
        $junk = 0;
        $canonicalized = 0;
        $examples = [];

        RestaurantSocialLink::query()->chunkById(2000, function (Collection $links) use ($quarantine, &$junk, &$canonicalized, &$examples): void {
            foreach ($links as $link) {
                $profile = SocialProfileUrl::canonicalize((string) $link->url);

                if ($profile === null) {
                    $junk++;
                    if (count($examples) < $this->sample) {
                        $examples[] = "#{$link->restaurant_id} {$link->platform}: {$link->url}";
                    }
                    if ($this->apply) {
                        $quarantine->quarantineSocialLink($link, 'social_not_a_profile', self::DETECTOR);
                        $this->touchedSocial[(int) $link->restaurant_id] = true;
                    }

                    continue;
                }

                if ($profile['url'] !== $link->url && $profile['platform'] === $link->platform) {
                    $canonicalized++;
                    if ($this->apply) {
                        $link->update(['url' => $profile['url']]);
                    }
                }
            }
        });

        $this->report('social_junk', $junk, $examples, ['canonical_url_rewrites' => $canonicalized]);

        return ['rows' => $junk, 'values' => $this->apply ? $junk : 0];
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function socialBrand(SocialLinkRecorder $recorder): array
    {
        $threshold = max(2, (int) config('restaurant-finder.data_integrity.social_brand_min_restaurants', 5));
        $shared = RestaurantSocialLink::query()
            ->where('scope', RestaurantSocialLink::SCOPE_LOCATION)
            ->groupBy('url')
            ->havingRaw('COUNT(DISTINCT restaurant_id) >= ?', [$threshold])
            ->pluck('url')
            ->map(fn ($url) => (string) $url)
            ->values()
            ->all();

        $links = $shared === [] ? 0 : RestaurantSocialLink::query()->whereIn('url', $shared)->where('scope', RestaurantSocialLink::SCOPE_LOCATION)->count();
        $examples = array_slice($shared, 0, $this->sample);

        $recounted = $this->apply ? $recorder->classifyBrandScope($shared) : 0;

        $this->report('social_brand', $links, $examples, ['shared_urls' => count($shared), 'restaurants_recounted' => $recounted]);

        return ['rows' => $links, 'values' => $this->apply ? $links : 0];
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function copiedRating(FieldQuarantineService $quarantine): array
    {
        $clusters = [];
        Restaurant::query()->active()->where('google_rating', '>', 0)
            ->get(['id', 'name', 'city', 'state', 'phone', 'google_rating', 'google_review_count'])
            ->each(function (Restaurant $r) use (&$clusters): void {
                $clusters[$this->nameKey($r->name).'|'.$r->google_rating.'|'.$r->google_review_count][] = $r;
            });

        $rows = 0;
        $values = 0;
        $keptOwners = 0;
        $examples = [];

        foreach ($clusters as $members) {
            if ($this->distinctCities($members) < 2) {
                continue;
            }

            $owner = $this->ratingOwner($members);
            if ($owner !== null) {
                $keptOwners++;
            }

            foreach ($members as $member) {
                if ($owner !== null && $member->id === $owner->id) {
                    continue;
                }
                $rows++;
                if (count($examples) < $this->sample) {
                    $examples[] = "{$member->name} ({$member->city}, {$member->state}) {$member->google_rating}★/{$member->google_review_count}"
                        .($owner !== null ? " — belongs to {$owner->city}, {$owner->state}" : ' — owner ambiguous');
                }
                if ($this->apply) {
                    $values += $quarantine->quarantineRating($member, 'rating_copied_across_cities', self::DETECTOR, ['owner_id' => $owner?->id]);
                }
            }
        }

        $this->report('copied_rating', $rows, $examples, ['clusters_with_identified_owner' => $keptOwners]);

        return ['rows' => $rows, 'values' => $values];
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function copiedPhone(FieldQuarantineService $quarantine): array
    {
        $clusters = [];
        Restaurant::query()->active()->whereNotNull('phone')->where('phone', '!=', '')
            ->get(['id', 'name', 'city', 'state', 'phone'])
            ->each(function (Restaurant $r) use (&$clusters): void {
                $digits = substr((string) preg_replace('/\D+/', '', (string) $r->phone), -10);
                if (strlen($digits) === 10) {
                    $clusters[$this->nameKey($r->name).'|'.$digits][] = $r;
                }
            });

        $rows = 0;
        $values = 0;
        $examples = [];

        foreach ($clusters as $members) {
            if ($this->distinctCities($members) < 2) {
                continue;
            }
            $phoneState = AreaCodeStates::stateForPhone($members[0]->phone);
            if ($phoneState === null) {
                continue; // toll-free / unknown code: no location judgement
            }

            foreach ($members as $member) {
                if ($this->stateOf($member->state) === $phoneState) {
                    continue;
                }
                $rows++;
                if (count($examples) < $this->sample) {
                    $examples[] = "{$member->name} ({$member->city}, {$member->state}) {$member->phone} — a {$phoneState} number";
                }
                if ($this->apply) {
                    $values += $quarantine->quarantineFields($member, ['phone'], 'phone_copied_other_state', self::DETECTOR, ['phone_state' => $phoneState]);
                }
            }
        }

        $this->report('copied_phone', $rows, $examples);

        return ['rows' => $rows, 'values' => $values];
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function addressOtherState(FieldQuarantineService $quarantine): array
    {
        $rows = 0;
        $values = 0;
        $examples = [];

        Restaurant::query()->active()->whereNotNull('address')->where('address', '!=', '')->whereNotNull('state')
            ->chunkById(2000, function (Collection $restaurants) use ($quarantine, &$rows, &$values, &$examples): void {
                foreach ($restaurants as $restaurant) {
                    // "…, Mobile, AL 36619" — the state token before a ZIP.
                    if (preg_match('/,\s*[A-Za-z .\'-]+,?\s+([A-Z]{2})\s+\d{5}(?:-\d{4})?\s*(?:,\s*(?:USA|US|United States))?\s*$/', (string) $restaurant->address, $m) !== 1) {
                        continue;
                    }
                    $addressState = StateAbbreviations::toAbbreviation($m[1]);
                    $rowState = $this->stateOf($restaurant->state);
                    if ($addressState === null || $rowState === null || $addressState === $rowState || $this->addressAtPin($restaurant)) {
                        continue;
                    }

                    $rows++;
                    if (count($examples) < $this->sample) {
                        $examples[] = "{$restaurant->name} ({$restaurant->city}, {$restaurant->state}) — {$restaurant->address}";
                    }
                    if (! $this->apply) {
                        continue;
                    }

                    $fields = ['address'];
                    // The phone was typically copied in the same backfill.
                    if (AreaCodeStates::stateForPhone($restaurant->phone) === $addressState) {
                        $fields[] = 'phone';
                    }
                    $values += $quarantine->quarantineFields($restaurant, $fields, 'address_other_state', self::DETECTOR, ['address_state' => $addressState]);
                }
            });

        $this->report('address_other_state', $rows, $examples);

        return ['rows' => $rows, 'values' => $values];
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function addressFarFromLocation(FieldQuarantineService $quarantine): array
    {
        $rows = 0;
        $values = 0;
        $examples = [];
        $leftToOverture = 0;
        $minKm = (float) config('restaurant-finder.data_integrity.address_zip_unmatched_far_km', 50);

        Restaurant::query()->active()->whereNotNull('address')->where('address', '!=', '')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->chunkById(2000, function (Collection $restaurants) use ($quarantine, $minKm, &$rows, &$values, &$examples, &$leftToOverture): void {
                foreach ($restaurants as $restaurant) {
                    // The address's own ZIP: a far postal_code alone doesn't
                    // prove the street wrong.
                    $zip = ZipLocation::zipOf($restaurant->address);
                    $lat = (float) $restaurant->latitude;
                    $lng = (float) $restaurant->longitude;
                    if ($zip === null || $this->nearCityCenter($lat, $lng)) {
                        continue;
                    }

                    if ($restaurant->overture_id !== null) {
                        $leftToOverture += ZipLocation::isFarFrom($zip, $lat, $lng) === true ? 1 : 0;

                        continue;
                    }
                    if (ZipLocation::isFarFrom($zip, $lat, $lng, $minKm) !== true) {
                        continue;
                    }

                    $rows++;
                    if (count($examples) < $this->sample) {
                        $km = (int) round((float) ZipLocation::distanceKm($zip, $lat, $lng));
                        $examples[] = "{$restaurant->name} ({$restaurant->city}, {$restaurant->state}) — {$restaurant->address} [ZIP {$zip} is {$km} km away]";
                    }
                    if (! $this->apply) {
                        continue;
                    }

                    $fields = ZipLocation::zipOf(null, $restaurant->postal_code) === $zip ? ['address', 'postal_code'] : ['address'];
                    $values += $quarantine->quarantineFields($restaurant, $fields, 'address_far_from_location', self::DETECTOR, [
                        'zip' => $zip, 'distance_km' => round((float) ZipLocation::distanceKm($zip, $lat, $lng)),
                    ]);
                }
            });

        $this->report('address_far_from_location', $rows, $examples, ['Overture-matched, left to overture:import' => $leftToOverture]);

        return ['rows' => $rows, 'values' => $values];
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function cityFarFromLocation(FieldQuarantineService $quarantine): array
    {
        $rows = 0;
        $values = 0;
        $examples = [];
        $counts = [
            'corrected from address' => 0, 'address restored' => 0, 'state corrected' => 0, 'state corrected, city removed' => 0,
            'removed' => 0, 'address state fixed' => 0, 'grid labels renamed' => 0, 'left, no evidence' => 0,
        ];
        $unmatchedFarKm = (float) config('restaurant-finder.data_integrity.city_unmatched_far_km', 150);

        Restaurant::query()->active()->whereNotNull('city')->where('city', '!=', '')
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->chunkById(2000, function (Collection $restaurants) use ($quarantine, $unmatchedFarKm, &$rows, &$values, &$examples, &$counts): void {
                foreach ($restaurants as $restaurant) {
                    $lat = (float) $restaurant->latitude;
                    $lng = (float) $restaurant->longitude;
                    $state = $this->stateOf($restaurant->state);
                    // The ZIP at the pin settles the state.
                    $zipState = $this->pinZipState($restaurant, $lat, $lng);
                    // No state: often a foreign venue. A pin on a city center
                    // with no ZIP there to vouch for it: likely a geocoding
                    // fallback, so the pin may be the wrong part.
                    if ($state === null || ($zipState === null && $this->nearCityCenter($lat, $lng))) {
                        continue;
                    }

                    $city = trim((string) $restaurant->city);
                    $place = PlaceLocation::withoutStateSuffix($city) ?? $city;
                    $details = ['city' => $city, 'state' => $restaurant->state];
                    // Counts the row once, however many of its fields change.
                    $flagged = false;
                    $note = function (string $what) use ($restaurant, $city, &$examples, &$flagged, &$rows): void {
                        $rows += $flagged ? 0 : 1;
                        $flagged = true;
                        if (count($examples) < $this->sample && $what !== '') {
                            $examples[] = "{$restaurant->name} ({$city}, {$restaurant->state}) — {$what}";
                        }
                    };

                    $address = $this->addressWithZipState((string) $restaurant->address, $lat, $lng);
                    if ($address !== null) {
                        $counts['address state fixed']++;
                        $note("address now {$address}");
                        $values += $this->apply ? $quarantine->replaceFields($restaurant, ['address' => $address], 'city_far_from_location', self::DETECTOR, $details) : 0;
                    }

                    // A place-name check settles whether the stored city is here.
                    $nearest = PlaceLocation::nearest($place, $state, $lat, $lng);
                    $cityHere = $nearest !== null && $nearest['km'] <= $nearest['far_km'];
                    $wrong = ($zipState !== null && $zipState !== $state)
                        || ($nearest !== null && ! $cityHere)
                        // A name the state has no place for, that is a place
                        // somewhere but nowhere near the pin ("Cheyenne, KY" on
                        // a Louisville venue). Anything else is a neighborhood.
                        || ($nearest === null && PlaceLocation::named($place) !== [] && PlaceLocation::statesAt($place, $lat, $lng) === []);

                    if (! $wrong) {
                        if ($place !== $city && $cityHere) {
                            $note('');
                            $counts['grid labels renamed']++;
                            $values += $this->apply ? $quarantine->replaceFields($restaurant, ['city' => $place], 'city_grid_label', self::DETECTOR, $details) : 0;
                        }

                        continue;
                    }

                    $evidence = $this->cityFromAddress((string) $restaurant->address, $lat, $lng, $zipState);
                    $restore = null;
                    if ($evidence === null && trim((string) $restaurant->address) === '') {
                        // address_other_state removed addresses that disagreed
                        // with the row's state. At the pin, the state was wrong.
                        $restore = FieldQuarantine::query()->where('restaurant_id', $restaurant->id)->where('field', 'address')
                            ->where('reason', 'address_other_state')->whereNull('restored_at')->latest('id')->first();
                        $evidence = $restore === null ? null : $this->cityFromAddress((string) $restore->old_value, $lat, $lng, $zipState);
                        $restore = $evidence === null ? null : $restore;
                    }
                    // The address names the stored city itself (a neighborhood
                    // such as Charlestown, MA): nothing to correct.
                    if ($evidence !== null && $evidence['state'] === $state && PlaceLocation::normalize($evidence['city']) === PlaceLocation::normalize($place)) {
                        continue;
                    }

                    if ($evidence === null && $zipState !== null && $zipState !== $state) {
                        $km = PlaceLocation::nearest($place, $zipState, $lat, $lng);
                        if ($km !== null && $km['km'] <= $km['far_km']) {
                            // "Kansas City, MO" on a Kansas venue.
                            $counts['state corrected']++;
                            $note("{$place}, {$zipState} per the ZIP");
                            $values += $this->apply ? $quarantine->replaceFields($restaurant, ['city' => $place, 'state' => $zipState], 'city_far_from_location', self::DETECTOR, $details) : 0;
                        } else {
                            // The state is certain, the city isn't here ("Omaha, NE"
                            // on a Council Bluffs, IA venue whose address names none).
                            $counts['state corrected, city removed']++;
                            $note("state {$zipState} per the ZIP, city removed");
                            if ($this->apply) {
                                $values += $quarantine->quarantineFields($restaurant, ['city'], 'city_far_from_location', self::DETECTOR, $details);
                                $values += $quarantine->replaceFields($restaurant, ['state' => $zipState], 'city_far_from_location', self::DETECTOR, $details);
                            }
                        }

                        continue;
                    }

                    $remove = $evidence === null && $zipState === null && $nearest !== null && $nearest['km'] > $unmatchedFarKm;
                    if ($evidence === null && ! $remove) {
                        $counts['left, no evidence']++;

                        continue;
                    }

                    $details['distance_km'] = $nearest === null ? null : (int) round($nearest['km']);
                    if ($evidence === null) {
                        $counts['removed']++;
                        $note(($details['distance_km'] ?? '?').' km away, no evidence: removed');
                        $values += $this->apply ? $quarantine->quarantineFields($restaurant, ['city', 'state'], 'city_far_from_location', self::DETECTOR, $details) : 0;

                        continue;
                    }

                    $counts['corrected from address']++;
                    $counts['address restored'] += $restore !== null ? 1 : 0;
                    $note("{$evidence['city']}, {$evidence['state']} per ".($restore === null ? 'its address' : 'its removed address, restored'));
                    if (! $this->apply) {
                        continue;
                    }
                    if ($restore !== null && $quarantine->restore($restore)) {
                        $values++;
                    }
                    $values += $quarantine->replaceFields($restaurant, $evidence, 'city_far_from_location', self::DETECTOR, $details);
                }
            });

        $this->report('city_far_from_location', $rows, $examples, $counts);

        return ['rows' => $rows, 'values' => $values];
    }

    /**
     * The city and state an address names, when the address is the pin's (its
     * ZIP, if any, is near the pin: a copied address names the copy's city).
     * The state comes from the ZIP, never the address text, which some sources
     * build with the search's state ("Silver Spring, DC 20910"). The city must
     * be a place at the pin in that state, or a name the Census doesn't list
     * there (a New England town, a township, a neighborhood) with a ZIP to
     * vouch for it, unless that name is a place at the pin across a state
     * line: the search's city written into the address ("151 American Way,
     * Washington, DC 20745" on a National Harbor, MD venue).
     *
     * @return array{city: string, state: string}|null
     */
    private function cityFromAddress(string $address, float $lat, float $lng, ?string $pinZipState): ?array
    {
        $city = AddressParts::city($address);
        $zip = ZipLocation::zipOf($address);
        if ($city === null || ($zip !== null && ZipLocation::isFarFrom($zip, $lat, $lng) !== false)) {
            return null;
        }

        // A search label glued into the address ("…, Washington DC, DC 22003").
        $city = PlaceLocation::withoutStateSuffix($city) ?? $city;
        $state = ($zip === null ? null : ZipLocation::state($zip)) ?? $pinZipState;
        if ($state !== null) {
            $far = PlaceLocation::isFarFrom($city, $state, $lat, $lng);

            return $far === false || ($far === null && PlaceLocation::statesAt($city, $lat, $lng) === [])
                ? ['city' => $city, 'state' => $state]
                : null;
        }

        // No ZIP: the one state where the city is a place at the pin.
        $states = PlaceLocation::statesAt($city, $lat, $lng);
        $named = AddressParts::state($address);
        if ($named !== null) {
            return in_array($named, $states, true) ? ['city' => $city, 'state' => $named] : null;
        }

        return count($states) === 1 ? ['city' => $city, 'state' => $states[0]] : null;
    }

    /** The state of a ZIP at the pin: the address's own ZIP, else postal_code. */
    private function pinZipState(Restaurant $restaurant, float $lat, float $lng): ?string
    {
        $zip = ZipLocation::zipOf($restaurant->address, $restaurant->postal_code);

        return $zip !== null && ZipLocation::isFarFrom($zip, $lat, $lng) === false ? ZipLocation::state($zip) : null;
    }

    /**
     * The address with its state token matching its own ZIP ("…, Silver
     * Spring, DC 20910" → "…, Silver Spring, MD 20910"), or null when it
     * already matches or can't be vouched for: the ZIP must be at the pin and
     * the address's city a place there, in the ZIP's state.
     */
    private function addressWithZipState(string $address, float $lat, float $lng): ?string
    {
        $named = AddressParts::state($address);
        $city = AddressParts::city($address);
        $zip = ZipLocation::zipOf($address);
        $zipState = $zip === null ? null : ZipLocation::state($zip);
        if ($named === null || $city === null || $zipState === null || $zipState === $named
            || ZipLocation::isFarFrom((string) $zip, $lat, $lng) !== false
            || PlaceLocation::isFarFrom($city, $zipState, $lat, $lng) !== false) {
            return null;
        }

        $fixed = preg_replace('/\b'.$named.'(\s+'.$zip.'(?:-\d{4})?)(\s*(?:,\s*(?:USA|US|United States)\.?)?)$/', $zipState.'$1$2', $address, 1, $replaced);

        return $replaced === 1 ? (string) $fixed : null;
    }

    /** The address's own ZIP sits at the pin. */
    private function addressAtPin(Restaurant $restaurant): bool
    {
        $zip = ZipLocation::zipOf($restaurant->address);

        return $zip !== null && $restaurant->latitude !== null && $restaurant->longitude !== null
            && ZipLocation::isFarFrom($zip, (float) $restaurant->latitude, (float) $restaurant->longitude) === false;
    }

    /** A pin within 500 m of a configured city center: likely a geocoding fallback. */
    private function nearCityCenter(float $lat, float $lng): bool
    {
        foreach ((array) config('restaurant-finder.cities', []) as $center) {
            if (is_array($center) && abs($center[0] - $lat) < 0.0045 && abs($center[1] - $lng) < 0.0045 / max(0.2, cos(deg2rad($lat)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{rows: int, values: int}
     */
    private function aiGuess(FieldQuarantineService $quarantine): array
    {
        $rows = 0;
        $values = 0;
        $examples = [];
        $byField = ['price_range' => 0, 'phone' => 0, 'website_url' => 0];

        Restaurant::query()->active()->whereNotNull('ai_metadata')
            ->chunkById(2000, function (Collection $restaurants) use ($quarantine, &$rows, &$values, &$examples, &$byField): void {
                foreach ($restaurants as $restaurant) {
                    $updated = is_array($restaurant->ai_metadata['fields_updated'] ?? null) ? $restaurant->ai_metadata['fields_updated'] : [];

                    $fields = [];
                    foreach (['price_range', 'phone'] as $field) {
                        if (in_array($field, $updated, true) && ! empty($restaurant->{$field})) {
                            $fields[] = $field;
                        }
                    }
                    if (in_array('website_url', $updated, true) && ! empty($restaurant->website_url)
                        && $restaurant->website_identity !== WebsiteIdentityVerifier::VERIFIED) {
                        $fields[] = 'website_url';
                    }
                    if ($fields === []) {
                        continue;
                    }

                    $rows++;
                    foreach ($fields as $field) {
                        $byField[$field]++;
                    }
                    if (count($examples) < $this->sample) {
                        $examples[] = "{$restaurant->name} ({$restaurant->city}) — AI-written ".implode(', ', $fields);
                    }
                    if ($this->apply) {
                        foreach ($fields as $field) {
                            $values += $quarantine->quarantineFields($restaurant, [$field], $field.'_ai_guess', self::DETECTOR);
                        }
                    }
                }
            });

        $this->report('ai_guess', $rows, $examples, $byField);

        return ['rows' => $rows, 'values' => $values];
    }

    private function restore(FieldQuarantineService $quarantine, SocialLinkRecorder $recorder, string $reason): int
    {
        $restored = 0;
        $kept = 0;
        $touched = [];

        FieldQuarantine::query()->where('reason', $reason)->whereNull('restored_at')
            ->chunkById(1000, function (Collection $entries) use ($quarantine, &$restored, &$kept, &$touched): void {
                foreach ($entries as $entry) {
                    if ($quarantine->restore($entry)) {
                        $restored++;
                        $touched[(int) $entry->restaurant_id] = true;
                    } else {
                        $kept++;
                    }
                }
            });

        $recorder->recount(array_keys($touched));

        $this->info("Restored {$restored} value(s) quarantined as '{$reason}'; {$kept} left in quarantine (a newer value now occupies the field).");
        Log::channel('enrichment')->info('Data integrity restore', ['reason' => $reason, 'restored' => $restored, 'kept' => $kept]);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $examples
     * @param  array<string, int>  $breakdown
     */
    private function report(string $detector, int $rows, array $examples, array $breakdown = []): void
    {
        $this->newLine();
        $this->line("<info>{$detector}</info>: {$rows} flagged"
            .($breakdown === [] ? '' : ' ('.implode(', ', array_map(fn ($k, $v) => "{$k} {$v}", array_keys($breakdown), $breakdown)).')'));
        foreach ($examples as $example) {
            $this->line('   · '.$example);
        }
    }

    private function nameKey(?string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii((string) $name))));
    }

    /**
     * @param  array<int, Restaurant>  $members
     */
    private function distinctCities(array $members): int
    {
        return count(array_unique(array_map(fn (Restaurant $r) => strtolower(trim((string) $r->city)), $members)));
    }

    private function stateOf(?string $state): ?string
    {
        return StateAbbreviations::toAbbreviation($state);
    }

    private function phoneMatchesState(Restaurant $restaurant): bool
    {
        $phoneState = AreaCodeStates::stateForPhone($restaurant->phone);

        return $phoneState !== null && $phoneState === $this->stateOf($restaurant->state);
    }

    /**
     * The venue a copied rating really belongs to, or null when it can't be
     * told apart (the rating is then removed from every member and SerpApi
     * re-rates the true venue by location).
     *
     * 1. The copy that spread the rating usually spread the donor's phone too:
     *    a phone held by several members points at the one member located in
     *    that number's area-code state.
     * 2. Otherwise, the single member whose own phone is local to its state.
     *
     * @param  array<int, Restaurant>  $members
     */
    private function ratingOwner(array $members): ?Restaurant
    {
        $holders = [];
        foreach ($members as $member) {
            $digits = substr((string) preg_replace('/\D+/', '', (string) $member->phone), -10);
            if (strlen($digits) === 10) {
                $holders[$digits][] = $member;
            }
        }

        foreach ($holders as $digits => $sharing) {
            if (count($sharing) < 2) {
                continue;
            }
            $state = AreaCodeStates::stateForPhone((string) $digits);
            $inState = array_values(array_filter($sharing, fn (Restaurant $r) => $state !== null && $this->stateOf($r->state) === $state));
            if (count($inState) === 1) {
                return $inState[0];
            }
        }

        $consistent = array_values(array_filter($members, fn (Restaurant $r) => $this->phoneMatchesState($r)));

        return count($consistent) === 1 ? $consistent[0] : null;
    }
}
