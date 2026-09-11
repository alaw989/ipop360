<?php

namespace App\Services\Overture;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Thin runner for the DuckDB CLI, used to read Overture's public GeoParquet
 * straight from S3 (and query the local extract).
 *
 * The binary is provisioned on first use: the pinned release's gzipped Linux
 * CLI is downloaded from GitHub, its SHA-256 is verified before anything is
 * written, and it is unpacked into storage/app/tools. No deploy-pipeline or
 * system package change is needed, and a tampered or truncated download can
 * never be executed.
 *
 * DuckDB installs extensions (httpfs, for S3) under $HOME/.duckdb. The
 * scheduler's www-data user can't write its home (/var/www), so every run
 * gets HOME pointed at storage/app/tools/duckdb-home instead.
 */
class DuckDb
{
    public const VERSION = '1.5.5';

    private const DOWNLOAD_URL = 'https://github.com/duckdb/duckdb/releases/download/v'.self::VERSION.'/duckdb_cli-linux-amd64.gz';

    /** sha256 of duckdb_cli-linux-amd64.gz for VERSION. */
    private const SHA256 = 'c61f21485e6e41d3a0c28ce9904ea18346309cf427b4cf9479bc3564348dc885';

    public function __construct(
        private ?string $binaryPath = null,
        private string $downloadUrl = self::DOWNLOAD_URL,
        private string $sha256 = self::SHA256,
        private ?string $homeDirectory = null,
    ) {}

    /**
     * Absolute path of a verified, executable DuckDB binary.
     */
    public function binary(): string
    {
        $path = $this->binaryPath ?? storage_path('app/tools/duckdb-'.self::VERSION);
        if (is_file($path) && is_executable($path)) {
            return $path;
        }

        $response = Http::timeout(180)->withOptions(['allow_redirects' => ['max' => 5]])->get($this->downloadUrl);
        if (! $response->successful()) {
            throw new RuntimeException("DuckDB download failed: HTTP {$response->status()}");
        }

        $gz = $response->body();
        if (! hash_equals($this->sha256, hash('sha256', $gz))) {
            throw new RuntimeException('DuckDB download checksum mismatch — refusing to install.');
        }

        $binary = gzdecode($gz);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('DuckDB download could not be unpacked.');
        }

        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        $tmp = $path.'.tmp';
        file_put_contents($tmp, $binary);
        chmod($tmp, 0755);
        rename($tmp, $path);

        return $path;
    }

    /**
     * Run a SQL script (statements separated by semicolons) and return stdout.
     *
     * @throws RuntimeException when DuckDB exits non-zero
     */
    public function run(string $sql, int $timeoutSeconds = 3600): string
    {
        $result = Process::timeout($timeoutSeconds)
            ->env(['HOME' => $this->home()])
            ->input($sql)
            ->run([$this->binary(), '-bail', ':memory:']);

        if (! $result->successful()) {
            throw new RuntimeException('DuckDB failed: '.trim(substr($result->errorOutput() ?: $result->output(), 0, 500)));
        }

        return $result->output();
    }

    /**
     * A HOME directory the app can write, for DuckDB's extension cache.
     */
    private function home(): string
    {
        $dir = $this->homeDirectory ?? storage_path('app/tools/duckdb-home');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir}");
        }

        return $dir;
    }

    /**
     * Run a single SELECT and decode its rows (DuckDB's JSON output mode).
     *
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, int $timeoutSeconds = 600): array
    {
        $output = trim($this->run(".mode json\n".rtrim($sql, "; \n").";\n", $timeoutSeconds));
        if ($output === '') {
            return [];
        }

        $rows = json_decode($output, true);
        if (! is_array($rows)) {
            throw new RuntimeException('DuckDB returned non-JSON output: '.substr($output, 0, 200));
        }

        return array_values(array_filter($rows, 'is_array'));
    }
}
