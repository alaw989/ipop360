<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Type/range drift-guard for config/restaurant-finder.php (spec-097).
 *
 * The file's three DATA CATALOGS — 'cities', 'city_states', 'cuisines' —
 * are lat/lng coordinate lists and name mappings, NOT tunable knobs, so they
 * are excluded entirely. Every operational knob is walked and asserted sane:
 * flags/kill-switches stay booleans, weights/fractions/thresholds stay in
 * [0,1], and limits/caps/counts/TTLs stay positive. A knob that doesn't fit a
 * generic bucket gets a narrower per-key assertion (RANGE_CHECKS), and string
 * or null knobs are only allowed at explicit allowlisted paths (STRING_KNOBS /
 * NULL_KNOBS). The test reads the file directly (not config()) so .env values
 * and config caching can't mask a drift in the committed file.
 */
class RestaurantFinderConfigInvariantTest extends TestCase
{
    /** Top-level sections that are data catalogs, never scanned. */
    private const DATA_CATALOGS = ['cities', 'city_states', 'cuisines'];

    /** Key fragments that mark a knob as a boolean kill-switch/flag. */
    private const FLAG_KEY_PATTERN = '/(enabled|guard|require_|credibility|scrutinize|paginate|phone_match|allow_user_create)/';

    /** Key fragments that mark a knob as a weight/fraction/threshold (must be [0,1]). */
    private const FRACTION_KEY_PATTERN = '/(weight|fraction|threshold)/';

    /** Key fragments that mark a knob as a limit/cap/count/ttl/budget (must be positive). */
    private const POSITIVE_KEY_PATTERN = '/(limit|cap|count|ttl|budget|hours|days|weeks|minutes|size|attempts|floor|max|min|radius|timeout|wait|quota|misses|combos|index|default|prior|reviews|results|scale|fallback)/';

    /**
     * Narrow [min, max] range assertions for knobs whose key doesn't cleanly
     * fit a generic bucket (a score floor that may be 0 = off, a 0-100
     * percentage threshold, a similarity, a decay floor). Consulted before the
     * generic classification.
     */
    private const RANGE_CHECKS = [
        'ranking.award_name_similarity' => [0, 1],
        'ranking.score_decay_floor' => [0, 1],
        'live_search.min_score' => [0, 1],
        'trending.min_popularity_score' => [0, 1],
        'dedup.name_similarity_threshold' => [0, 100],
    ];

    /** Operational sections (i.e. the config minus the data catalogs). */
    private const OPERATIONAL_SECTIONS = [
        'ranking',
        'live_search',
        'cache',
        'sources',
        'serpapi',
        'free_sources',
        'enrich',
        'dedup',
        'filters',
        'website_scraper',
        'require_verified_social_links',
        'trending',
        'homepage',
        'favorites',
    ];

    /** Knobs whose committed value is intentionally a string. */
    private const STRING_KNOBS = ['sources.photon.base_url'];

    /** Knobs whose committed value is intentionally null (env-optional). */
    private const NULL_KNOBS = [
        'live_search.distance_fallback_lat',
        'live_search.distance_fallback_lng',
    ];

    public function test_top_level_keys_match_the_known_section_list(): void
    {
        $config = $this->loadCommittedConfig();

        $this->assertSame(
            [...self::DATA_CATALOGS, ...self::OPERATIONAL_SECTIONS],
            array_keys($config),
            'config/restaurant-finder.php has an unexpected top-level section; add it to '
            .'RestaurantFinderConfigInvariantTest::OPERATIONAL_SECTIONS (or DATA_CATALOGS if it is a data catalog).'
        );
    }

    public function test_every_operational_knob_has_a_sane_type_and_range(): void
    {
        $config = $this->loadCommittedConfig();

        foreach (self::OPERATIONAL_SECTIONS as $section) {
            $this->assertArrayHasKey($section, $config, "Missing operational section '{$section}'");

            if (is_array($config[$section])) {
                $this->walkSection($config[$section], $section);
            } else {
                $this->assertKnobSane($section, $section, $config[$section]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function walkSection(array $data, string $path): void
    {
        foreach ($data as $key => $value) {
            $fullPath = $path.'.'.$key;

            if (is_array($value)) {
                // Sequential arrays (amenities, mirrors, garbage-word lists,
                // popular_cities, ...) are data lists, not knob sections.
                if (array_is_list($value)) {
                    continue;
                }
                $this->walkSection($value, $fullPath);

                continue;
            }

            if (is_string($value)) {
                $this->assertTrue(
                    in_array($fullPath, self::STRING_KNOBS, true),
                    "{$fullPath} is a string knob but is not in STRING_KNOBS; if intentional, allowlist it: "
                    .var_export($value, true)
                );

                continue;
            }

            if ($value === null) {
                $this->assertTrue(
                    in_array($fullPath, self::NULL_KNOBS, true),
                    "{$fullPath} resolved to null but is not in NULL_KNOBS; if intentional, allowlist it."
                );

                continue;
            }

            $this->assertKnobSane($fullPath, $key, $value);
        }
    }

    private function assertKnobSane(string $fullPath, string $key, int|float|bool $value): void
    {
        $lower = strtolower($key);

        // 1) Narrow per-key range check (score floors that may be 0, the 0-100
        //    percentage threshold, similarity, decay floor).
        if (isset(self::RANGE_CHECKS[$fullPath])) {
            [$min, $max] = self::RANGE_CHECKS[$fullPath];
            $this->assertTrue(
                $value >= $min && $value <= $max,
                "{$fullPath} must be numeric in [{$min}, {$max}], got: ".var_export($value, true)
            );

            return;
        }

        // 2) Kill-switches/flags must stay booleans (a literal bool today, or
        //    a flag-suggesting key).
        if (is_bool($value) || preg_match(self::FLAG_KEY_PATTERN, $lower)) {
            $this->assertTrue(
                is_bool($value),
                "{$fullPath} must be a boolean (kill-switch/flag), got: ".var_export($value, true)
            );

            return;
        }

        // 3) Weights/fractions/thresholds must stay in [0,1].
        if (str_contains($fullPath, '.weights.') || preg_match(self::FRACTION_KEY_PATTERN, $lower)) {
            $this->assertTrue(
                $value >= 0 && $value <= 1,
                "{$fullPath} must be numeric in [0,1] (weight/fraction/threshold), got: ".var_export($value, true)
            );

            return;
        }

        // 4) Limits/caps/counts/TTLs/budgets must be positive.
        if (preg_match(self::POSITIVE_KEY_PATTERN, $lower)) {
            $this->assertTrue(
                $value > 0,
                "{$fullPath} must be a positive number, got: ".var_export($value, true)
            );

            return;
        }

        $this->fail("{$fullPath} does not match any knob category; add a RANGE_CHECKS entry or extend the classification.");
    }

    /**
     * Evaluate config/restaurant-finder.php as COMMITTED: the file's env()
     * calls resolve against the live process environment, so a dev .env that
     * overrides a knob (e.g. RANK_WEIGHT_QUALITY=0.35) would mask a drift in
     * the file's own default. Snapshot and clear just those env keys from
     * getenv/$_ENV/$_SERVER around the require so env() falls back to the
     * file's defaults, then restore.
     *
     * @return array<string, mixed>
     */
    private function loadCommittedConfig(): array
    {
        $env = (string) file_get_contents(config_path('restaurant-finder.php'));
        preg_match_all("/env\('([A-Z_0-9]+)'/", $env, $matches);
        $keys = array_unique($matches[1]);

        $savedEnv = $_ENV;
        $savedServer = $_SERVER;
        $savedGetenv = getenv();

        try {
            foreach ($keys as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }

            return require config_path('restaurant-finder.php');
        } finally {
            foreach ($keys as $key) {
                if (isset($savedEnv[$key])) {
                    $_ENV[$key] = $savedEnv[$key];
                }
                if (isset($savedServer[$key])) {
                    $_SERVER[$key] = $savedServer[$key];
                }
                if (isset($savedGetenv[$key])) {
                    putenv($key.'='.$savedGetenv[$key]);
                }
            }
        }
    }
}
