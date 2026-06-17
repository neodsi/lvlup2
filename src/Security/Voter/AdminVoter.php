<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   admin:access      – ROLE_MODERATOR or higher
 *   admin:impersonate – ROLE_ADMIN only
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

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$token->getUser() instanceof User) {
            return false;
        }

        // $token->getRoleNames() returns the fully hierarchy-expanded role list.
        $roles = $token->getRoleNames();

        return match ($attribute) {
            self::ACCESS      => in_array('ROLE_MODERATOR', $roles, true),
            self::IMPERSONATE => in_array('ROLE_ADMIN', $roles, true),
            default           => false,
        };
    }
}
