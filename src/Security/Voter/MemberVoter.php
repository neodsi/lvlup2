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
 *   members:view             – team_teacher+
 *   members:create           – team_admin+
 *   members:update           – team_admin+
 *   members:delete           – team_admin+
 *   members:export_csv       – team_admin+
 *   team_profiles:export_csv – team_admin+
 *
 * Subject: Team (team-scoped actions) or TeamProfile (member-scoped actions).
 */
final class MemberVoter extends Voter
{
    public const VIEW               = 'members:view';
    public const CREATE             = 'members:create';
    public const UPDATE             = 'members:update';
    public const DELETE             = 'members:delete';
    public const EXPORT_CSV         = 'members:export_csv';
    public const TEAM_PROFILES_EXPORT_CSV = 'team_profiles:export_csv';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
        self::EXPORT_CSV,
        self::TEAM_PROFILES_EXPORT_CSV,
    ];

    public function __construct(
        private readonly TeamProfileRepository $teamProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)) {
            return false;
        }

        return $subject instanceof Team || $subject instanceof TeamProfile;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        $teamId = match (true) {
            $subject instanceof Team        => $subject->getId(),
            $subject instanceof TeamProfile => $subject->getTeam()?->getId(),
            default                         => null,
        };

        if ($teamId === null) {
            return false;
        }

        $teamRole = $this->resolveTeamRole($user, $teamId);

        if ($teamRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW                    => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamTeacher),
            self::CREATE                  => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::UPDATE                  => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::DELETE                  => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::EXPORT_CSV              => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::TEAM_PROFILES_EXPORT_CSV => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            default                       => false,
        };
    }

    private function resolveTeamRole(User $user, string $teamId): ?TeamRole
    {
        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, $teamId);

        return $teamProfile?->getRole();
    }
}
