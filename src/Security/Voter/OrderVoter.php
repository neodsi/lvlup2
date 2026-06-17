<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Security\TeamRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   orders:view   – team member who owns the order OR team_teacher+
 *   orders:create – any authenticated user who is a team member
 *   orders:update – team_admin+
 *   orders:delete – team_admin+
 */
final class OrderVoter extends Voter
{
    public const VIEW   = 'orders:view';
    public const CREATE = 'orders:create';
    public const UPDATE = 'orders:update';
    public const DELETE = 'orders:delete';

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
            && $subject instanceof Order;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Order $order */
        $order = $subject;

        $teamRole = $this->resolveTeamRole($user, $order->getTeamId());

        if ($teamRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => $this->canView($user, $order, $teamRole),
            self::CREATE => true,
            self::UPDATE => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            self::DELETE => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            default      => false,
        };
    }

    private function canView(User $user, Order $order, TeamRole $teamRole): bool
    {
        // Teacher+ can view any order in their team.
        if (TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamTeacher)) {
            return true;
        }

        // Otherwise the member may only view their own order.
        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, $order->getTeamId());

        return $teamProfile !== null && $teamProfile->getId() === $order->getTeamProfileId();
    }

    private function resolveTeamRole(User $user, string $teamId): ?TeamRole
    {
        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, $teamId);

        return $teamProfile?->getRole();
    }
}
