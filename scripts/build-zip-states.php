#!/usr/bin/env php
<?php

/*
 * Builds database/data/zip_states.php (read by App\Support\ZipLocation::state)
 * from two US Census files (public domain):
 *
 *   curl -O https://www2.census.gov/geo/docs/maps-data/data/rel2020/zcta520/tab20_zcta520_county20_natl.txt
 *   curl -O https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2024_Gazetteer/2024_Gaz_place_national.zip
 *   unzip 2024_Gaz_place_national.zip
 *   php scripts/build-zip-states.php tab20_zcta520_county20_natl.txt 2024_Gaz_place_national.txt > database/data/zip_states.php
 *
 * A ZCTA's state is the state of the counties holding most of its land; the
 * place file supplies the state FIPS code → USPS abbreviation mapping.
 */

$relationship = $argv[1] ?? null;
$placesFile = $argv[2] ?? null;
if (! $relationship || ! is_readable($relationship) || ! $placesFile || ! is_readable($placesFile)) {
    fwrite(STDERR, "Usage: php scripts/build-zip-states.php <tab20_zcta520_county20_natl.txt> <2024_Gaz_place_national.txt>\n");
    exit(2);
}

$usps = [];
$handle = fopen($placesFile, 'r');
fgetcsv($handle, null, "\t", '"', '');
while (($row = fgetcsv($handle, null, "\t", '"', '')) !== false) {
    $usps[substr(trim($row[1]), 0, 2)] = trim($row[0]);
}
fclose($handle);

$land = [];
$handle = fopen($relationship, 'r');
$header = array_map(fn ($h) => trim($h, "\u{FEFF} "), (array) fgetcsv($handle, null, '|', '"', ''));
$zctaCol = array_search('GEOID_ZCTA5_20', $header, true);
$countyCol = array_search('GEOID_COUNTY_20', $header, true);
$landCol = array_search('AREALAND_PART', $header, true);
while (($row = fgetcsv($handle, null, '|', '"', '')) !== false) {
    $zcta = trim((string) $row[$zctaCol]);
    $state = $usps[substr(trim((string) $row[$countyCol]), 0, 2)] ?? null;
    if ($zcta === '' || $state === null) {
        continue;
    }
    $land[$zcta][$state] = ($land[$zcta][$state] ?? 0) + (int) $row[$landCol];
}
fclose($handle);
ksort($land, SORT_STRING);

echo "<?php\n\n";
echo "// US ZIP Code Tabulation Area => state (the state holding most of its land).\n";
echo "// Source: US Census Bureau 2020 ZCTA-to-county relationship file (public domain),\n";
echo "// https://www2.census.gov/geo/docs/maps-data/data/rel2020/zcta520/tab20_zcta520_county20_natl.txt\n";
echo "// Built by scripts/build-zip-states.php; read by App\\Support\\ZipLocation.\n\n";
echo "return [\n";
foreach ($land as $zcta => $states) {
    arsort($states);
    echo "    '{$zcta}' => '".array_key_first($states)."',\n";
}
echo "];\n";
