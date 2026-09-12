<?php

namespace App\Support;

/**
 * The city and state a one-line street address names. Supports the shapes the
 * corpus mixes:
 *   - US style: "622 E Adams St, Phoenix, AZ 85004" (city before the "ST ZIP"
 *     tail), also without the ZIP ("…, Novi, MI") or with a trailing ", USA".
 *   - OSM/BizData style: "West Southern Avenue, 706, Mesa, 85210" (street,
 *     house number, city, zip), also without the zip ("North 28th Drive,
 *     12418, Phoenix").
 * Guarded so a street-only address, an address without a city segment, or a
 * junk tail (foreign postal codes) never yields a bogus city.
 */
final class AddressParts
{
    public static function city(?string $address): ?string
    {
        return self::parse($address)[0];
    }

    /** The 2-letter state before the ZIP ("…, Phoenix, AZ 85004"), or null. */
    public static function state(?string $address): ?string
    {
        return self::parse($address)[1];
    }

    /**
     * @return array{0: ?string, 1: ?string} [city, state]
     */
    private static function parse(?string $address): array
    {
        $parts = array_values(array_filter(
            array_map('trim', explode(',', (string) $address)),
            fn ($part) => $part !== ''
        ));
        if ($parts !== [] && preg_match('/^(?:USA|US|United States)\.?$/i', $parts[count($parts) - 1]) === 1) {
            array_pop($parts);
        }

        $count = count($parts);
        if ($count < 2) {
            return [null, null];
        }

        $last = $parts[$count - 1];

        // US "ST ZIP" tail: "Phoenix, AZ 85004"; or just the state: "Novi, MI".
        if (preg_match('/^([A-Z]{2})(\s+\d{5}(?:-\d{4})?)?$/', $last, $m) === 1) {
            $isState = in_array($m[1], StateAbbreviations::MAP, true);
            if (($m[2] ?? '') !== '' || ($count >= 3 && $isState)) {
                $city = self::plausibleCity($parts[$count - 2]);

                return [$city, $city !== null && $isState ? $m[1] : null];
            }
        }

        // OSM numeric-zip tail: "Mesa, 85210".
        if (preg_match('/^\d{5}(?:-\d{4})?$/', $last) === 1 && $count >= 3) {
            return [self::plausibleCity($parts[$count - 2]), null];
        }

        // OSM without zip: "North 28th Drive, 12418, Phoenix".
        if ($count === 3 && preg_match('/^\d+$/', $parts[1]) === 1) {
            return [self::plausibleCity($parts[2]), null];
        }

        return [null, null];
    }

    /**
     * A city token worth persisting, or null. Rejects digit-led tokens (house
     * numbers, zip codes), very short tokens, and non-letter junk so nothing
     * bogus is stored as a city.
     */
    private static function plausibleCity(string $candidate): ?string
    {
        if (mb_strlen($candidate) < 2 || preg_match('/^\d/', $candidate) === 1) {
            return null;
        }

        return preg_match('/^[A-Za-z][A-Za-z .\'\-]+$/', $candidate) === 1 ? $candidate : null;
    }
}
