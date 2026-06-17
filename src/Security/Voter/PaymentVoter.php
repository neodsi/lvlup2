<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Payment;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Security\TeamRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   payments:view   – any team member
 *   payments:create – any team member
 *   payments:refund – team_admin+ AND the payment belongs to their team
 */
final class PaymentVoter extends Voter
{
    public const VIEW   = 'payments:view';
    public const CREATE = 'payments:create';
    public const REFUND = 'payments:refund';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::REFUND,
    ];

    public function __construct(
        private readonly TeamProfileRepository $teamProfileRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof Payment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Payment $payment */
        $payment = $subject;

        $teamRole = $this->resolveTeamRole($user, $payment->getTeamId());

        if ($teamRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::CREATE => true,
            self::REFUND => TeamRoleHierarchy::isGranted($teamRole, TeamRole::TeamAdmin),
            default      => false,
        };
    }

    private function resolveTeamRole(User $user, string $teamId): ?TeamRole
    {
        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, $teamId);

        return $teamProfile?->getRole();
    }
}
