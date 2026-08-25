<?php

namespace App\Support;

/**
 * Canonical US state/territory name ↔ 2-letter abbreviation lookup, shared
 * between the write-side DataHygiene sweep and any call site that needs to
 * reconcile a full state name (from IP/GPS/city-search geolocation) against
 * the DB's abbreviation-only convention.
 */
final class StateAbbreviations
{
    /**
     * Full US state/territory names keyed by their uppercase form.
     *
     * @var array<string, string>
     */
    public const MAP = [
        'ALABAMA' => 'AL',
        'ALASKA' => 'AK',
        'ARIZONA' => 'AZ',
        'ARKANSAS' => 'AR',
        'CALIFORNIA' => 'CA',
        'COLORADO' => 'CO',
        'CONNECTICUT' => 'CT',
        'DELAWARE' => 'DE',
        'FLORIDA' => 'FL',
        'GEORGIA' => 'GA',
        'HAWAII' => 'HI',
        'IDAHO' => 'ID',
        'ILLINOIS' => 'IL',
        'INDIANA' => 'IN',
        'IOWA' => 'IA',
        'KANSAS' => 'KS',
        'KENTUCKY' => 'KY',
        'LOUISIANA' => 'LA',
        'MAINE' => 'ME',
        'MARYLAND' => 'MD',
        'MASSACHUSETTS' => 'MA',
        'MICHIGAN' => 'MI',
        'MINNESOTA' => 'MN',
        'MISSISSIPPI' => 'MS',
        'MISSOURI' => 'MO',
        'MONTANA' => 'MT',
        'NEBRASKA' => 'NE',
        'NEVADA' => 'NV',
        'NEW HAMPSHIRE' => 'NH',
        'NEW JERSEY' => 'NJ',
        'NEW MEXICO' => 'NM',
        'NEW YORK' => 'NY',
        'NORTH CAROLINA' => 'NC',
        'NORTH DAKOTA' => 'ND',
        'OHIO' => 'OH',
        'OKLAHOMA' => 'OK',
        'OREGON' => 'OR',
        'PENNSYLVANIA' => 'PA',
        'RHODE ISLAND' => 'RI',
        'SOUTH CAROLINA' => 'SC',
        'SOUTH DAKOTA' => 'SD',
        'TENNESSEE' => 'TN',
        'TEXAS' => 'TX',
        'UTAH' => 'UT',
        'VERMONT' => 'VT',
        'VIRGINIA' => 'VA',
        'WASHINGTON' => 'WA',
        'WEST VIRGINIA' => 'WV',
        'WISCONSIN' => 'WI',
        'WYOMING' => 'WY',
        'DISTRICT OF COLUMBIA' => 'DC',
    ];

    /**
     * Resolve any recognizable form of a US state (full name or
     * abbreviation, any case/whitespace) to its canonical 2-letter
     * abbreviation. Returns null for anything unrecognized (junk, foreign,
     * empty) — callers decide their own fallback policy for that case.
     */
    public static function toAbbreviation(?string $state): ?string
    {
        if ($state === null) {
            return null;
        }

        $state = trim(preg_replace('/\s+/', ' ', $state) ?? '');
        if ($state === '') {
            return null;
        }

        $upper = strtoupper($state);

        if (isset(self::MAP[$upper])) {
            return self::MAP[$upper];
        }

        if (in_array($upper, self::MAP, true)) {
            return $upper;
        }

        return null;
    }
}
