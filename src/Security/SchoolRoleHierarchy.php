<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\SchoolRole;

final class SchoolRoleHierarchy
{
    private static function weights(): array
    {
        return [
            SchoolRole::Student->value => 1,
            SchoolRole::Teacher->value => 2,
            SchoolRole::Admin->value   => 3,
            SchoolRole::Owner->value   => 4,
        ];
    }

    /**
     * Returns true when $userRole satisfies the $requiredRole level.
     * Hierarchy: owner > admin > teacher > student.
     */
    public static function isGranted(SchoolRole $userRole, SchoolRole $requiredRole): bool
    {
        $weights = self::weights();
        return ($weights[$userRole->value] ?? 0) >= ($weights[$requiredRole->value] ?? 0);
    }
}
