<?php

namespace Tests\Unit;

use App\Services\Overture\DuckDb;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * DuckDb provisions its CLI binary from a pinned, checksum-verified download
 * — a tampered or wrong file is never written, let alone executed.
 */
class DuckDbTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/duckdb-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/duckdb');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_verified_download_is_unpacked_as_an_executable(): void
    {
        $gz = (string) gzencode("#!/bin/sh\necho v-test\n");
        Http::fake(['*' => Http::response($gz)]);

        $duck = new DuckDb($this->dir.'/duckdb', 'https://example.test/duckdb.gz', hash('sha256', $gz));
        $path = $duck->binary();

        $this->assertSame($this->dir.'/duckdb', $path);
        $this->assertTrue(is_executable($path));
        $this->assertSame("#!/bin/sh\necho v-test\n", file_get_contents($path));
    }

    public function test_checksum_mismatch_refuses_to_install(): void
    {
        Http::fake(['*' => Http::response((string) gzencode('tampered'))]);

        $duck = new DuckDb($this->dir.'/duckdb', 'https://example.test/duckdb.gz', str_repeat('0', 64));

        try {
            $duck->binary();
            $this->fail('expected a checksum failure');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('checksum mismatch', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->dir.'/duckdb');
    }

    public function test_existing_binary_is_reused_without_downloading(): void
    {
        mkdir($this->dir, 0755, true);
        file_put_contents($this->dir.'/duckdb', "#!/bin/sh\n");
        chmod($this->dir.'/duckdb', 0755);
        Http::fake();

        (new DuckDb($this->dir.'/duckdb'))->binary();

        Http::assertNothingSent();
    }
}
