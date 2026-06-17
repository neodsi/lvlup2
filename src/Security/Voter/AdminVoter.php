<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Enum\AppRole;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   admin:access      – app_moderator+
 *   admin:impersonate – app_super_admin only
 *
 * Subject: null (no specific resource needed — these are app-level permissions).
 */
final class AdminVoter extends Voter
{
    public const ACCESS      = 'admin:access';
    public const IMPERSONATE = 'admin:impersonate';

    private const SUPPORTED_ATTRIBUTES = [
        self::ACCESS,
        self::IMPERSONATE,
    ];

    private static function appRoleWeights(): array
    {
        return [
            AppRole::AppDefault->value    => 1,
            AppRole::AppModerator->value  => 2,
            AppRole::AppAdmin->value      => 3,
            AppRole::AppSuperAdmin->value => 4,
        ];
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // subject is unused for app-level permissions (pass null from callers).
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $appRole = $user->getAppRole();

        return match ($attribute) {
            self::ACCESS      => $this->isAppRoleGranted($appRole, AppRole::AppModerator),
            self::IMPERSONATE => $appRole === AppRole::AppSuperAdmin,
            default           => false,
        };
    }

    private function isAppRoleGranted(AppRole $userRole, AppRole $requiredRole): bool
    {
        $weights = self::appRoleWeights();
        return ($weights[$userRole->value] ?? 0) >= ($weights[$requiredRole->value] ?? 0);
    }
}
