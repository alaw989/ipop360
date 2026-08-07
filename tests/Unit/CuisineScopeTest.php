<?php

namespace Tests\Unit;

use App\Services\CuisineScope;
use Tests\TestCase;

class CuisineScopeTest extends TestCase
{
    /** @param array<int, string> $targetSlugs */
    /** @param array<int, string> $onKeywords */
    /** @param array<int, string> $rivalKeywords */
    private function scope(
        bool $requested = true,
        bool $resolved = true,
        array $targetSlugs = ['ethiopian'],
        array $onKeywords = [],
        array $rivalKeywords = [],
    ): CuisineScope {
        return new CuisineScope(
            requested: $requested,
            resolved: $resolved,
            queryTerm: 'Ethiopian',
            primarySlug: 'ethiopian',
            targetSlugs: $targetSlugs,
            onKeywords: $onKeywords,
            rivalKeywords: $rivalKeywords,
            label: 'Ethiopian',
        );
    }

    public function test_unscoped_when_nothing_requested(): void
    {
        $scope = $this->scope(requested: false, resolved: false, targetSlugs: []);

        $this->assertTrue($scope->isUnscoped());
        $this->assertFalse($scope->isScoped());
        $this->assertFalse($scope->isInvalid());
    }

    public function test_scoped_when_requested_and_resolved(): void
    {
        $scope = $this->scope(requested: true, resolved: true, targetSlugs: ['ethiopian']);

        $this->assertTrue($scope->isScoped());
        $this->assertFalse($scope->isUnscoped());
        $this->assertFalse($scope->isInvalid());
    }

    public function test_invalid_when_requested_but_not_resolved(): void
    {
        $scope = $this->scope(requested: true, resolved: false, targetSlugs: []);

        $this->assertTrue($scope->isInvalid());
        $this->assertFalse($scope->isUnscoped());
        $this->assertFalse($scope->isScoped());
    }

    public function test_scoped_exposes_resolved_taxonomy_data(): void
    {
        $scope = $this->scope(
            requested: true,
            resolved: true,
            targetSlugs: ['ethiopian', 'nigerian'],
            onKeywords: ['injera', 'jollof'],
            rivalKeywords: ['sushi'],
        );

        $this->assertSame('Ethiopian', $scope->queryTerm);
        $this->assertSame('ethiopian', $scope->primarySlug);
        $this->assertSame(['ethiopian', 'nigerian'], $scope->targetSlugs);
        $this->assertSame(['injera', 'jollof'], $scope->onKeywords);
        $this->assertSame(['sushi'], $scope->rivalKeywords);
        $this->assertSame('Ethiopian', $scope->label);
    }

    public function test_state_predicates_are_mutually_exclusive(): void
    {
        // Every valid combination of requested/resolved must land in exactly one state.
        $cases = [
            [false, false, 'unscoped'],
            [true, true, 'scoped'],
            [true, false, 'invalid'],
        ];

        foreach ($cases as [$requested, $resolved, $expected]) {
            $scope = $this->scope(requested: $requested, resolved: $resolved);
            $active = array_filter([
                'unscoped' => $scope->isUnscoped(),
                'scoped' => $scope->isScoped(),
                'invalid' => $scope->isInvalid(),
            ]);

            $this->assertSame([$expected], array_keys($active), "requested={$requested} resolved={$resolved}");
        }
    }
}
