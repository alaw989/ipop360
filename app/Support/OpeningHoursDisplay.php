<?php

namespace App\Support;

/**
 * Stored opening hours, ready to show as a week.
 *
 * Rows store hours four ways, depending on where they came from:
 *
 * - OpenStreetMap's `opening_hours` text, "Mo-Fr 10:30-24:00; Sa,Su 11:00-24:00"
 *   (most rows, from Overpass and Overture);
 * - Google's day map, {"monday": "11 AM–9 PM", "tuesday": "Closed", …};
 * - a website's schema.org hours, {"structured": true, "hours": [{day, open, close}]};
 * - scraped free text, {"structured": false, "raw_text": "…"}.
 *
 * Whatever the source, a restaurant page gets either the seven days in order,
 * each with its hours in 12-hour time ("11 AM – 9 PM", "Closed"), or, when the
 * text can't be read as a week, the text itself with any markup removed.
 * Days that OpenStreetMap text or a schema.org list leaves out are closed, by
 * those formats' own rules; a day missing from Google's map is unknown and is
 * left out.
 */
class OpeningHoursDisplay
{
    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const OSM_DAYS = ['Mo' => 0, 'Tu' => 1, 'We' => 2, 'Th' => 3, 'Fr' => 4, 'Sa' => 5, 'Su' => 6];

    private const CLOSED = 'Closed';

    /**
     * @return array{structured: true, week: list<array{day: string, hours: string}>}|array{structured: false, raw_text: string}|null
     */
    public static function present(mixed $stored): ?array
    {
        if (is_string($stored)) {
            return self::fromText($stored);
        }

        if (! is_array($stored) || $stored === []) {
            return null;
        }

        if (($stored['structured'] ?? null) === true && is_array($stored['hours'] ?? null)) {
            return self::fromSchemaList($stored['hours']);
        }

        if (($stored['structured'] ?? null) === false) {
            return is_string($stored['raw_text'] ?? null) ? self::fromText($stored['raw_text']) : null;
        }

        return self::fromDayMap($stored);
    }

    /**
     * OpenStreetMap syntax when it parses, the cleaned text otherwise.
     *
     * @return array{structured: true, week: list<array{day: string, hours: string}>}|array{structured: false, raw_text: string}|null
     */
    private static function fromText(string $text): ?array
    {
        $week = self::parseOsm($text);
        if ($week !== null) {
            return self::week($week);
        }

        $clean = html_entity_decode(strip_tags((string) preg_replace('/<br\s*\/?>/i', "\n", $text)), ENT_QUOTES | ENT_HTML5);
        $lines = array_map('trim', preg_split('/\R/u', $clean) ?: []);
        $clean = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)));

        return $clean === '' ? null : ['structured' => false, 'raw_text' => $clean];
    }

    /**
     * Reads the common subset of OpenStreetMap's opening_hours: day ranges and
     * lists, one or more time spans, "off", "24/7", and rules separated by ";"
     * or ",". Anything else — months, weeks, sunrise, "open end" — isn't a
     * plain week, so the answer is null.
     *
     * By the spec, a ";" rule replaces what earlier rules said about its days
     * ("Mo-Su 11:00-22:00; Fr-Sa 11:00-23:00" closes later on weekends). Many
     * entries use it to list a second service instead ("Mo-Fr 11:00-14:00;
     * Mo-Th 17:00-21:00"), so hours that don't overlap the earlier ones are
     * added to them rather than replacing them.
     *
     * @return array<int, string>|null hours by day index, Monday = 0
     */
    private static function parseOsm(string $text): ?array
    {
        $text = str_replace(['–', '—'], '-', $text);
        $text = trim((string) preg_replace(['/\s+/', '/\s*-\s*/', '/\s*,\s*/'], [' ', '-', ','], $text));
        if ($text === '') {
            return null;
        }
        if (strcasecmp($text, '24/7') === 0) {
            return array_fill(0, 7, 'Open 24 hours');
        }

        $day = '(?:Mo|Tu|We|Th|Fr|Sa|Su|PH)';
        $time = '\d{1,2}:\d{2}';
        $rulePattern = "/^(?:(?<days>{$day}(?:-{$day})?(?:,{$day}(?:-{$day})?)*) )?(?<times>{$time}-{$time}(?:,{$time}-{$time})*|off|closed)$/i";

        /** @var array<int, list<array{0: int, 1: int}>> $week open spans in minutes, by day; [] is closed */
        $week = [];
        // A "," is a list inside a rule unless a time or "off" ends the rule before it.
        foreach (preg_split("/;|(?<=\d|off|closed),(?={$day})/i", $text) ?: [] as $rule) {
            $rule = trim($rule);
            // Blank, or about public holidays only ("PH off", "PH unknown").
            if ($rule === '' || preg_match('/^PH\b(?![,-])/i', $rule)) {
                continue;
            }
            if (! preg_match($rulePattern, $rule, $m)) {
                return null;
            }
            $days = $m['days'] === '' ? range(0, 6) : self::osmDays($m['days']);
            $spans = in_array(strtolower($m['times']), ['off', 'closed'], true) ? [] : self::osmSpans($m['times']);
            if ($spans === null) {
                return null;
            }
            foreach ($days as $d) {
                $week[$d] = $spans !== [] && isset($week[$d]) && ! self::overlaps($week[$d], $spans)
                    ? array_merge($week[$d], $spans)
                    : $spans;
            }
        }

        if ($week === []) {
            return null;
        }

        return array_map(function (array $spans) {
            if ($spans === []) {
                return self::CLOSED;
            }
            usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);

            return implode(', ', array_map(
                fn (array $s) => 24 * 60 <= $s[1] - $s[0] ? 'Open 24 hours' : self::clock($s[0]).' – '.self::clock($s[1]),
                $spans,
            ));
        }, $week) + array_fill(0, 7, self::CLOSED);
    }

    /**
     * "Mo-Fr,Su" → [0, 1, 2, 3, 4, 6]. Ranges may wrap ("Fr-Mo"). Holidays
     * ("PH") name no weekday, so they're passed over.
     *
     * @return list<int>
     */
    private static function osmDays(string $selector): array
    {
        $days = [];
        foreach (explode(',', $selector) as $part) {
            $ends = array_map(fn (string $d) => ucfirst(strtolower($d)), explode('-', $part));
            if (in_array('Ph', $ends, true)) {
                continue;
            }
            $from = self::OSM_DAYS[$ends[0]];
            $to = self::OSM_DAYS[$ends[1] ?? $ends[0]];
            for ($d = $from; ; $d = ($d + 1) % 7) {
                $days[] = $d;
                if ($d === $to) {
                    break;
                }
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * "11:00-14:00,17:00-21:00" → [[660, 840], [1020, 1260]], in minutes from
     * midnight. A span past midnight ("18:00-02:00") closes the next day.
     *
     * @return list<array{0: int, 1: int}>|null
     */
    private static function osmSpans(string $spans): ?array
    {
        $out = [];
        foreach (explode(',', $spans) as $span) {
            [$open, $close] = array_map(function (string $hhmm) {
                [$h, $m] = array_map('intval', explode(':', $hhmm));

                return $h > 48 || $m > 59 ? null : $h * 60 + $m;
            }, explode('-', $span));
            if ($open === null || $close === null) {
                return null;
            }
            $out[] = [$open, $close <= $open ? $close + 24 * 60 : $close];
        }

        return $out;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $a
     * @param  list<array{0: int, 1: int}>  $b
     */
    private static function overlaps(array $a, array $b): bool
    {
        foreach ($a as [$aOpen, $aClose]) {
            foreach ($b as [$bOpen, $bClose]) {
                if ($aOpen < $bClose && $bOpen < $aClose) {
                    return true;
                }
            }
        }

        return false;
    }

    /** 630 → "10:30 AM", 1020 → "5 PM", 1440 → "12 AM", 1500 → "1 AM". */
    private static function clock(int $minutes): string
    {
        $h = intdiv($minutes, 60) % 24;
        $m = $minutes % 60;
        $suffix = $h < 12 ? 'AM' : 'PM';
        $h12 = $h % 12 === 0 ? 12 : $h % 12;

        return $m === 0 ? "{$h12} {$suffix}" : sprintf('%d:%02d %s', $h12, $m, $suffix);
    }

    /**
     * Google's map from day name to that day's hours.
     *
     * @param  array<mixed>  $map
     * @return array{structured: true, week: list<array{day: string, hours: string}>}|null
     */
    private static function fromDayMap(array $map): ?array
    {
        $week = [];
        foreach (self::DAYS as $i => $name) {
            $hours = $map[strtolower($name)] ?? $map[$name] ?? null;
            if (is_string($hours) && trim($hours) !== '') {
                $week[$i] = trim($hours);
            }
        }

        return $week === [] ? null : self::week($week);
    }

    /**
     * A schema.org list: one entry per open span, so a day with a lunch and a
     * dinner service has two.
     *
     * @param  array<mixed>  $entries
     * @return array{structured: true, week: list<array{day: string, hours: string}>}|null
     */
    private static function fromSchemaList(array $entries): ?array
    {
        $week = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['day'] ?? null)) {
                continue;
            }
            $i = array_search(ucfirst(strtolower(trim($entry['day']))), self::DAYS, true);
            $open = is_string($entry['open'] ?? null) ? trim($entry['open']) : '';
            $close = is_string($entry['close'] ?? null) ? trim($entry['close']) : '';
            if ($i === false || $open === '' || $close === '') {
                continue;
            }
            $span = "{$open} – {$close}";
            $week[$i] = isset($week[$i]) ? $week[$i].', '.$span : $span;
        }

        return $week === [] ? null : self::week($week + array_fill(0, 7, self::CLOSED));
    }

    /**
     * @param  array<int, string>  $week  hours by day index, Monday = 0
     * @return array{structured: true, week: list<array{day: string, hours: string}>}
     */
    private static function week(array $week): array
    {
        ksort($week);

        return [
            'structured' => true,
            'week' => array_map(
                fn (int $i, string $hours) => ['day' => self::DAYS[$i], 'hours' => $hours],
                array_keys($week),
                $week,
            ),
        ];
    }
}
