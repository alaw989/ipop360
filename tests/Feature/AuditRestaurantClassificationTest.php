<?php

namespace Tests\Feature;

use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class AuditRestaurantClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_flags_but_does_not_quarantine(): void
    {
        $bad = Restaurant::factory()->create(['name' => 'Southern Bail Bonds Dallas', 'is_active' => true]);
        $good = Restaurant::factory()->create(['name' => 'Southern Comfort Kitchen', 'is_active' => true]);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:audit-classification');
        $cmd->expectsOutputToContain('Flagged: 1');

        $freshBad = $bad->fresh();
        $freshGood = $good->fresh();
        $this->assertNotNull($freshBad);
        $this->assertNotNull($freshGood);
        $this->assertTrue($freshBad->is_active);
        $this->assertTrue($freshGood->is_active);
    }

    public function test_apply_quarantines_flagged_rows_only(): void
    {
        $bad = Restaurant::factory()->create(['name' => 'Southern Bail Bonds Dallas', 'is_active' => true]);
        $good = Restaurant::factory()->create(['name' => 'Southern Comfort Kitchen', 'is_active' => true]);

        $this->artisan('restaurants:audit-classification', ['--apply' => true]);

        $freshBad = $bad->fresh();
        $freshGood = $good->fresh();
        $this->assertNotNull($freshBad);
        $this->assertNotNull($freshGood);
        $this->assertFalse($freshBad->is_active);
        $this->assertTrue($freshGood->is_active);
    }

    public function test_flags_by_stored_place_types_when_present(): void
    {
        $bad = Restaurant::factory()->create([
            'name' => 'Riverfront Office',
            'is_active' => true,
            'place_types' => ['lawyer'],
        ]);

        $this->artisan('restaurants:audit-classification', ['--apply' => true]);

        $freshBad = $bad->fresh();
        $this->assertNotNull($freshBad);
        $this->assertFalse($freshBad->is_active);
    }

    public function test_ignores_already_inactive_rows(): void
    {
        $alreadyInactive = Restaurant::factory()->create(['name' => 'Some Bail Bonds Office', 'is_active' => false]);

        /** @var PendingCommand $cmd */
        $cmd = $this->artisan('restaurants:audit-classification');
        $cmd->expectsOutputToContain('No active restaurants to audit.');

        $fresh = $alreadyInactive->fresh();
        $this->assertNotNull($fresh);
        $this->assertFalse($fresh->is_active);
    }
}
