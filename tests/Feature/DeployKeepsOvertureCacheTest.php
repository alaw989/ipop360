<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * overture:import keeps its DuckDB binary + extension cache in
 * storage/app/tools and its ~150 MB extract in storage/app/overture. The
 * deploy's `rsync --delete` removes anything the checkout lacks, so without
 * these excludes every deploy wiped them — and a deploy during the monthly
 * run deleted its files mid-run.
 */
class DeployKeepsOvertureCacheTest extends TestCase
{
    public function test_deploy_rsync_keeps_the_overture_tool_and_extract_dirs(): void
    {
        $deploy = file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertNotFalse($deploy, 'deploy.yml must be readable');

        foreach (['storage/app/tools/', 'storage/app/overture/'] as $dir) {
            $this->assertStringContainsString(
                "--exclude '{$dir}'",
                $deploy,
                "the deploy rsync --delete must exclude {$dir} or every deploy wipes it"
            );
        }
    }
}
