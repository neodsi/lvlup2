<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Order;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Security\SchoolRoleHierarchy;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $em,
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
            self::UPDATE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::DELETE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
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
        $profile = $user->getProfile();

        return $profile !== null && $profile->getId() === $order->getProfileId();
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $profile = $user->getProfile();
        if ($profile === null) {
            return null;
        }

        $school = $this->em->getRepository(\App\Entity\School::class)->find($schoolId);
        if ($school !== null && $school->getOwnerProfileId() === $profile->getId()) {
            return SchoolRole::School;
        }

        $sps = $this->em->createQueryBuilder()
            ->select('sps')
            ->from(SchoolProfileSeason::class, 'sps')
            ->where('sps.profileId = :profileId')
            ->andWhere('sps.schoolId = :schoolId')
            ->orderBy('sps.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('profileId', $profile->getId())
            ->setParameter('schoolId', $schoolId)
            ->getQuery()
            ->getOneOrNullResult();

        return $sps?->getRole();
    }
}
