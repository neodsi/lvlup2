<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Season;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Security\TeamRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   seasons:view   – any team member
 *   seasons:create – team_admin+
 *   seasons:update – team_admin+
 *   seasons:delete – team_admin+
 */
final class SeasonVoter extends Voter
{
    public const VIEW   = 'seasons:view';
    public const CREATE = 'seasons:create';
    public const UPDATE = 'seasons:update';
    public const DELETE = 'seasons:delete';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
    ];

    public function __construct(
        private readonly TeamProfileRepository $teamProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof Season;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Season $season */
        $season = $subject;

        $teamRole = $this->resolveTeamRole($user, $season->getTeamId());

        if ($teamRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::CREATE => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::UPDATE => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::DELETE => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            default      => false,
        };
    }

    private function resolveTeamRole(User $user, string $teamId): ?TeamRole
    {
        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, $teamId);

        return $teamProfile?->getRole();
    }
}
