<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Services\UserRoleService;
use PHPUnit\Framework\TestCase;

class UserRoleServiceTest extends TestCase
{
    public function test_valid_values_lists_every_enum_value(): void
    {
        $this->assertSame(
            array_map(fn (UserRole $role) => $role->value, UserRole::cases()),
            UserRoleService::validValues(),
        );
    }
}
