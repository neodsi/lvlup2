<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Payment;
use App\Entity\School;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Security\SchoolRoleHierarchy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   payments:view   – any school member
 *   payments:create – any school member
 *   payments:refund – admin+ AND the payment belongs to their school
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
        private readonly EntityManagerInterface $em,
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

        $schoolRole = $this->resolveSchoolRole($user, $payment->getSchoolId());

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::CREATE => true,
            self::REFUND => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            default      => false,
        };
    }

    private function resolveSchoolRole(User $user, string $schoolId): ?SchoolRole
    {
        $profile = $user->getProfile();
        if ($profile === null) {
            return null;
        }

        $school = $this->em->getRepository(School::class)->find($schoolId);
        if ($school !== null && $school->getOwnerProfileId() !== null && $school->getOwnerProfileId() === $profile->getId()) {
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
