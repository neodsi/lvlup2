<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Event;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Security\TeamRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   events:view   – any team member
 *   events:create – team_admin+
 *   events:update – team_admin+
 *   events:delete – team_admin+
 */
final class EventVoter extends Voter
{
    public const VIEW   = 'events:view';
    public const CREATE = 'events:create';
    public const UPDATE = 'events:update';
    public const DELETE = 'events:delete';

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
            && $subject instanceof Event;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Event $event */
        $event = $subject;

        $teamRole = $this->resolveTeamRole($user, $event->getTeamId());

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
