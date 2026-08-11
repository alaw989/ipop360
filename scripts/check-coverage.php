#!/usr/bin/env php
<?php

$cloverPath = $argv[1] ?? null;

if (! $cloverPath || ! file_exists($cloverPath)) {
    fwrite(STDERR, "Usage: php scripts/check-coverage.php <path-to-clover.xml>\n");
    exit(2);
}

$thresholds = [
    'statements' => (int) ($argv[2] ?? 50),
    'conditionals' => (int) ($argv[3] ?? 45),
    'methods' => (int) ($argv[4] ?? 45),
];

$xml = simplexml_load_file($cloverPath);

if (! $xml || ! isset($xml->project)) {
    fwrite(STDERR, "Error: Invalid or empty Clover XML at $cloverPath\n");
    exit(2);
}

$metrics = $xml->project->metrics;
$attrs = $metrics->attributes();

$totals = [
    'statements' => (int) $attrs['statements'],
    'covered' => (int) $attrs['coveredstatements'],
    'conditionals' => (int) $attrs['conditionals'],
    'covered_cond' => (int) $attrs['coveredconditionals'],
    'methods' => (int) $attrs['methods'],
    'covered_meth' => (int) $attrs['coveredmethods'],
];

$actual = [
    'statements' => $totals['statements'] > 0 ? round(($totals['covered'] / $totals['statements']) * 100, 2) : 0,
    'conditionals' => $totals['conditionals'] > 0 ? round(($totals['covered_cond'] / $totals['conditionals']) * 100, 2) : 0,
    'methods' => $totals['methods'] > 0 ? round(($totals['covered_meth'] / $totals['methods']) * 100, 2) : 0,
];

// Drivers like pcov emit conditionals="0" (no branch/conditional metric), so
// skip any metric the driver reports as unmeasured rather than failing on it.
$measured = array_filter($totals, fn ($total, $metric) => $total > 0, ARRAY_FILTER_USE_BOTH);

echo "\n┌──────────────────────────────┐\n";
echo "│  PHPUnit Coverage Thresholds │\n";
echo "├──────────┬────────┬──────────┤\n";
echo "│ Metric   │ Actual │ Required │\n";
echo "├──────────┼────────┼──────────┤\n";

$failed = false;

foreach (['statements', 'conditionals', 'methods'] as $metric) {
    $totalAttr = match ($metric) {
        'statements' => 'statements',
        'conditionals' => 'conditionals',
        'methods' => 'methods',
    };
    if (($measured[$totalAttr] ?? 0) === 0) {
        echo "│ {$metric} │ skipped │ driver N/A │  -\n";

        continue;
    }
    $label = str_pad(ucfirst($metric), 8);
    $actualStr = str_pad($actual[$metric].'%', 6);
    $requiredStr = str_pad($thresholds[$metric].'%', 8);
    $status = $actual[$metric] >= $thresholds[$metric] ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";
    echo "│ {$label} │ {$actualStr} │ {$requiredStr} │ {$status}\n";
    if ($actual[$metric] < $thresholds[$metric]) {
        $failed = true;
    }
}

echo "└──────────┴────────┴──────────┘\n\n";

if ($failed) {
    echo "\033[31mCoverage thresholds not met.\033[0m\n";
    exit(1);
}

echo "\033[32mAll coverage thresholds met.\033[0m\n";
exit(0);
