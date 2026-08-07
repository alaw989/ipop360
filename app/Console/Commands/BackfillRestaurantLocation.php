<?php

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\GeolocationService;
use Illuminate\Console\Command;

class BackfillRestaurantLocation extends Command
{
    protected $signature = 'restaurants:backfill-location
        {--dry-run : Show what would be updated without making changes}
        {--limit=0 : Max restaurants to process per phase (0 = unlimited)}
        {--phase=all : Which phase to run (parse|geocode|all)}';

    protected $description = 'Backfill missing city/state from address strings and reverse geocoding';

    public function handle(GeolocationService $geolocation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $phase = $this->option('phase');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be made');
        }

        if ($phase === 'all' || $phase === 'parse') {
            $this->parseAddresses($dryRun, $limit);
        }

        if ($phase === 'all' || $phase === 'geocode') {
            $this->reverseGeocode($geolocation, $dryRun, $limit);
        }

        $remaining = Restaurant::whereNull('city')->count();
        $this->newLine();
        $this->info("Done. {$remaining} restaurants still missing city.");

        return self::SUCCESS;
    }

    private function parseAddresses(bool $dryRun, int $limit): void
    {
        $query = Restaurant::whereNull('city')
            ->whereNotNull('address')
            ->where('address', '!=', '');

        $total = $query->count();
        $this->info("Phase 1: Parsing city/state from {$total} addresses...");

        $updated = 0;
        $parsed = 0;

        $query->when($limit > 0, fn ($q) => $q->limit($limit))
            ->each(function (Restaurant $r) use ($dryRun, &$updated, &$parsed) {
                $result = $this->extractCityState($r->address);
                if ($result === null) {
                    return;
                }

                $parsed++;
                if ($dryRun) {
                    $this->line("  [DRY] #{$r->id} {$r->name} → city={$result['city']}".($result['state'] ? ", state={$result['state']}" : ''));

                    return;
                }

                $r->city = $result['city'];
                if ($result['state'] !== null) {
                    $r->state = $result['state'];
                }
                $r->save();
                $updated++;
            });

        $this->info("  Parsed: {$parsed}, Updated: {$updated}");
    }

    private function reverseGeocode(GeolocationService $geolocation, bool $dryRun, int $limit): void
    {
        $query = Restaurant::whereNull('city')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('latitude', '!=', 0)
            ->where('longitude', '!=', 0);

        $total = $query->count();
        $this->info("Phase 2: Reverse geocoding {$total} restaurants via Nominatim...");

        if ($dryRun) {
            $this->warn('  Skipping geocode in dry-run mode (would make API calls)');

            return;
        }

        $attempted = 0;
        $updated = 0;
        $failed = 0;
        $totalToProcess = $query->count();

        $query->when($limit > 0, fn ($q) => $q->limit($limit))
            ->each(function (Restaurant $r) use ($geolocation, &$attempted, &$updated, &$failed, $totalToProcess) {
                $attempted++;

                $result = $geolocation->reverseGeocode($r->latitude, $r->longitude);

                if ($result === null) {
                    $failed++;
                } else {
                    $r->city = $result['city'];
                    if ($result['state'] !== null) {
                        $r->state = $result['state'];
                    }
                    $r->save();
                    $updated++;
                }

                // Nominatim usage policy: max 1 request per second
                if ($attempted < $totalToProcess) {
                    sleep(1);
                }

                if ($attempted % 50 === 0) {
                    $this->line("  ... {$attempted} attempted, {$updated} updated, {$failed} failed");
                }
            });

        $this->info("  Attempted: {$attempted}, Updated: {$updated}, Failed: {$failed}");
    }

    /** @return array{city: string, state: string|null}|null */
    private function extractCityState(string $address): ?array
    {
        if (empty($address)) {
            return null;
        }

        $parts = array_map('trim', explode(',', $address));
        $n = count($parts);

        if ($n < 2) {
            return null;
        }

        $last = $parts[$n - 1];
        $second = $parts[$n - 2] ?? '';

        $city = null;
        $state = null;

        if ($n >= 3 && preg_match('/^([A-Z]{2})\s+\d{5}(?:-\d{4})?$/', $last, $sm)) {
            $state = $sm[1];
            $city = $second;
        } elseif (preg_match('/^\d{5}(?:-\d{4})?$/', $last) && ! preg_match('/^[A-Z]{2}$/', $second)) {
            if ($n >= 4) {
                $city = $second;
            }
        } elseif (! preg_match('/^\d/', $last) && ! $this->isNonCityToken($last)) {
            $city = $last;
        }

        if ($city === null || $this->isNonCityToken($city)) {
            return null;
        }

        return ['city' => $city, 'state' => $state];
    }

    private function isNonCityToken(string $value): bool
    {
        return preg_match('/^\d/', $value)
            || in_array(strtolower($value), [
                '', 'inc', 'llc', 'suite', 'ste', 'unit', 'ph', 'bldg', 'fl',
                'north', 'south', 'east', 'west', 'floor', 'room', 'apt', 'box',
            ], true);
    }
}
