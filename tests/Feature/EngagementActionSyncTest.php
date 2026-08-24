<?php

namespace Tests\Feature;

use App\Http\Controllers\EngagementController;
use ReflectionClass;
use Tests\TestCase;

/**
 * EngagementController::store() increments a restaurants column live per
 * action_type; restaurants:update-engagement (UpdateEngagement.php) resyncs
 * the same columns nightly from a SUM() over the append-only
 * restaurant_engagement log. The two independently hardcode the same
 * action_type -> column mapping (a SQL CASE WHEN in one, a PHP match() in the
 * other) with nothing enforcing they stay in lockstep. If a new action_type
 * is ever added to one and not the other, the nightly job would silently
 * overwrite live increments with an undercount for that column. This test
 * fails loudly on that drift instead.
 */
class EngagementActionSyncTest extends TestCase
{
    public function test_every_engagement_action_type_is_aggregated_by_update_engagement(): void
    {
        $reflection = new ReflectionClass(EngagementController::class);
        /** @var array<string, string> $allowedActions */
        $allowedActions = $reflection->getConstant('ALLOWED_ACTIONS');

        $this->assertNotEmpty($allowedActions);

        $updateEngagementSource = file_get_contents(
            app_path('Console/Commands/UpdateEngagement.php')
        );
        $this->assertIsString($updateEngagementSource);

        foreach (array_unique(array_values($allowedActions)) as $actionType) {
            $this->assertStringContainsString(
                "'{$actionType}'",
                $updateEngagementSource,
                "restaurants:update-engagement has no aggregation branch for action_type '{$actionType}' "
                .'tracked by EngagementController::ALLOWED_ACTIONS — the nightly resync will silently '
                .'undercount this column.'
            );
        }
    }
}
