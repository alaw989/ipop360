<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReadPathIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_cuisines_has_standalone_slug_index(): void
    {
        $index = collect(Schema::getIndexes('cuisines'))
            ->first(fn ($idx) => $idx['columns'] === ['slug']);

        $this->assertNotNull($index, 'cuisines.slug should have its own index, not just the composite');
        $this->assertFalse($index['unique']);
    }

    public function test_external_api_cache_has_expires_at_index(): void
    {
        $index = collect(Schema::getIndexes('external_api_cache'))
            ->first(fn ($idx) => $idx['columns'] === ['expires_at']);

        $this->assertNotNull($index);
    }

    public function test_external_api_cache_has_fetched_at_index(): void
    {
        $index = collect(Schema::getIndexes('external_api_cache'))
            ->first(fn ($idx) => $idx['columns'] === ['fetched_at']);

        $this->assertNotNull($index);
    }
}
