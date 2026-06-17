<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Team;
use App\Entity\TeamProfile;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Security\TeamRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   teams:view             – any team member
 *   teams:update           – team_admin+
 *   teams:delete           – team_owner only
 *   teams:configure_stripe – team_admin+
 */
final class TeamVoter extends Voter
{
    public const VIEW             = 'teams:view';
    public const UPDATE           = 'teams:update';
    public const DELETE           = 'teams:delete';
    public const CONFIGURE_STRIPE = 'teams:configure_stripe';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::UPDATE,
        self::DELETE,
        self::CONFIGURE_STRIPE,
    ];

    public function __construct(
        private readonly TeamProfileRepository $teamProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof Team;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Team $team */
        $team = $subject;

        $teamRole = $this->resolveTeamRole($user, $team->getId());

        if ($teamRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW             => true,
            self::UPDATE           => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::DELETE           => $teamRole === TeamRole::TeamOwner,
            self::CONFIGURE_STRIPE => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            default                => false,
        };
    }

    private function resolveTeamRole(User $user, string $teamId): ?TeamRole
    {
        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, $teamId);

        return $teamProfile?->getRole();
    }
}
