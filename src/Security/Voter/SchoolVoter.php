<?php

declare(strict_types=1);

namespace App\Security\Voter;

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
 *   schools:view             – any school member
 *   schools:update           – admin+
 *   schools:delete           – owner only
 *   schools:configure_stripe – admin+
 */
final class SchoolVoter extends Voter
{
    public const VIEW             = 'schools:view';
    public const UPDATE           = 'schools:update';
    public const DELETE           = 'schools:delete';
    public const CONFIGURE_STRIPE = 'schools:configure_stripe';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::UPDATE,
        self::DELETE,
        self::CONFIGURE_STRIPE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, self::SUPPORTED_ATTRIBUTES, true)
            && $subject instanceof School;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var School $school */
        $school = $subject;

        $schoolRole = $this->resolveSchoolRole($user, $school);

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW             => true,
            self::UPDATE           => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::DELETE           => $schoolRole === SchoolRole::School,
            self::CONFIGURE_STRIPE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            default                => false,
        };
    }

    private function resolveSchoolRole(User $user, School $school): ?SchoolRole
    {
        $profile = $user->getProfile();
        if ($profile === null) {
            return null;
        }

        // Check school ownership first (valid even without a season)
        if ($school->getOwnerProfileId() === $profile->getId()) {
            return SchoolRole::School;
        }

        // Find role from any SchoolProfileSeason for this school
        $sps = $this->em->createQueryBuilder()
            ->select('sps')
            ->from(SchoolProfileSeason::class, 'sps')
            ->where('sps.profileId = :profileId')
            ->andWhere('sps.schoolId = :schoolId')
            ->orderBy('sps.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('profileId', $profile->getId())
            ->setParameter('schoolId', $school->getId())
            ->getQuery()
            ->getOneOrNullResult();

        return $sps?->getRole();
    }
}
