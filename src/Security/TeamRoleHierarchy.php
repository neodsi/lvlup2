<?php

declare(strict_types=1);

namespace App\Security;

use App\Enum\TeamRole;

final class TeamRoleHierarchy
{
    private static function weights(): array
    {
        return [
            TeamRole::TeamStudent->value => 1,
            TeamRole::TeamTeacher->value => 2,
            TeamRole::TeamAdmin->value   => 3,
            TeamRole::TeamOwner->value   => 4,
        ];
    }

    /**
     * Returns true when $userRole satisfies the $requiredRole level.
     * Hierarchy: owner > admin > teacher > student.
     */
    public static function isGranted(TeamRole $userRole, TeamRole $requiredRole): bool
    {
        $weights = self::weights();
        return ($weights[$userRole->value] ?? 0) >= ($weights[$requiredRole->value] ?? 0);
    }
}
