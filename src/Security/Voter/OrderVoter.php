<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolProfileRepository;
use App\Security\SchoolRoleHierarchy;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   orders:view   – school member who owns the order OR teacher+
 *   orders:create – any authenticated user who is a school member
 *   orders:update – admin+
 *   orders:delete – admin+
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
        private readonly SchoolProfileRepository $schoolProfileRepository,
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

        $schoolRole = $this->resolveSchoolRole($user, $order->getSchoolId());

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => $this->canView($user, $order, $schoolRole),
            self::CREATE => true,
            self::UPDATE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::Admin),
            self::DELETE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::Admin),
            default      => false,
        };
    }

    private function canView(User $user, Order $order, SchoolRole $schoolRole): bool
    {
        // Teacher+ can view any order in their school.
        if (SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::Teacher)) {
            return true;
        }

        // Otherwise the member may only view their own order.
        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($user, $order->getSchoolId());

        return $schoolProfile !== null && $schoolProfile->getId() === $order->getSchoolProfileId();
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($user, $schoolId);

        return $schoolProfile?->getRole();
    }
}
