#!/usr/bin/env php
<?php

/*
 * Builds database/data/place_centroids.php (read by App\Support\PlaceLocation)
 * from the US Census Gazetteer place file:
 *
 *   curl -O https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2024_Gazetteer/2024_Gaz_place_national.zip
 *   unzip 2024_Gaz_place_national.zip
 *   php scripts/build-place-centroids.php 2024_Gaz_place_national.txt > database/data/place_centroids.php
 *
 * Names are keyed by PlaceLocation::normalize() without the Census descriptor
 * ("Austin city" → "austin"). Consolidated city-counties are also listed by
 * their city ("Macon-Bibb County" → "macon", "Nashville-Davidson metropolitan
 * government (balance)" → "nashville"), plus a few everyday names the Census
 * spells differently ("Urban Honolulu", "Boise City") and New York City's
 * boroughs, which are not Census places.
 */

use App\Support\PlaceLocation;

require __DIR__.'/../vendor/autoload.php';

$path = $argv[1] ?? null;
if (! $path || ! is_readable($path)) {
    fwrite(STDERR, "Usage: php scripts/build-place-centroids.php <2024_Gaz_place_national.txt>\n");
    exit(2);
}

// LSAD code => the descriptor the Census appends to the name.
$descriptors = [
    '21' => 'borough', '25' => 'city', '35' => 'metro township', '37' => 'municipality', '43' => 'town',
    '47' => 'village', '53' => 'city and borough', '55' => 'comunidad', '57' => 'CDP', '62' => 'zona urbana',
    'CN' => 'corporation',
];
// Consolidated governments: listed by the city before the "-" or "/".
$consolidated = ['00', 'CG', 'MG', 'UC', 'UG'];
$aliases = ['HI|Urban Honolulu' => ['Honolulu'], 'ID|Boise City' => ['Boise']];
$boroughs = ['Brooklyn', 'Queens', 'Bronx', 'The Bronx', 'Staten Island', 'Manhattan'];

$places = [];
$add = function (string $name, string $state, array $point) use (&$places): void {
    $key = PlaceLocation::normalize($name);
    if ($key !== '' && ! in_array($point, $places[$key][$state] ?? [], true)) {
        $places[$key][$state][] = $point;
    }
};

$handle = fopen($path, 'r');
fgetcsv($handle, null, "\t", '"', '');
while (($row = fgetcsv($handle, null, "\t", '"', '')) !== false) {
    [$state, , , $name, $lsad, , $aland] = array_map('trim', $row);
    $lat = (float) trim($row[10]);
    $lng = (float) trim($row[11]);
    $point = [round($lat, 4), round($lng, 4), round(sqrt((float) $aland / M_PI) / 1000, 1)];

    $balance = str_ends_with($name, ' (balance)');
    $name = preg_replace('/ \(balance\)$/', '', $name);
    $descriptor = $descriptors[$lsad] ?? ($balance ? 'city' : null);
    if ($descriptor !== null && str_ends_with($name, ' '.$descriptor)) {
        $name = substr($name, 0, -strlen(' '.$descriptor));
    }

    $add($name, $state, $point);
    if (in_array($lsad, $consolidated, true) && preg_match('#^([^-/]+)[-/]#', $name, $m) === 1) {
        $add($m[1], $state, $point);
    }
    foreach ($aliases[$state.'|'.$name] ?? [] as $alias) {
        $add($alias, $state, $point);
    }
    if ($state === 'NY' && $name === 'New York') {
        foreach ($boroughs as $borough) {
            $add($borough, $state, $point);
        }
    }
}
fclose($handle);
ksort($places);

echo "<?php\n\n";
echo "// US Census places: normalized name => [state => [[lat, lng, radius_km], ...]].\n";
echo "// Source: US Census Bureau 2024 Gazetteer, place national file (public domain),\n";
echo "// https://www2.census.gov/geo/docs/maps-data/data/gazetteer/2024_Gazetteer/2024_Gaz_place_national.zip\n";
echo "// lat/lng = INTPTLAT/INTPTLONG (4 decimals); radius_km = radius of a circle\n";
echo "// with the place's land area (ALAND). Built by scripts/build-place-centroids.php;\n";
echo "// read by App\\Support\\PlaceLocation.\n\n";
echo "return [\n";
foreach ($places as $key => $states) {
    ksort($states);
    $parts = [];
    foreach ($states as $state => $points) {
        $parts[] = "'{$state}' => [".implode(', ', array_map(fn (array $p) => "[{$p[0]}, {$p[1]}, {$p[2]}]", $points)).']';
    }
    echo '    '.var_export((string) $key, true).' => ['.implode(', ', $parts)."],\n";
}
echo "];\n";
