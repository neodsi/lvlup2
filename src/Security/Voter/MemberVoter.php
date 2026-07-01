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
 *   members:view             – teacher+
 *   members:create           – admin+
 *   members:update           – admin+
 *   members:delete           – admin+
 *   members:export_csv       – admin+
 *   school_profiles:export_csv – admin+
 *
 * Subject: School (school-scoped actions).
 */
final class MemberVoter extends Voter
{
    public const VIEW               = 'members:view';
    public const CREATE             = 'members:create';
    public const UPDATE             = 'members:update';
    public const DELETE             = 'members:delete';
    public const EXPORT_CSV         = 'members:export_csv';
    public const SCHOOL_PROFILES_EXPORT_CSV = 'school_profiles:export_csv';

    private const SUPPORTED_ATTRIBUTES = [
        self::VIEW,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
        self::EXPORT_CSV,
        self::SCHOOL_PROFILES_EXPORT_CSV,
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

        /** @var School $subject */
        $schoolRole = $this->resolveSchoolRole($user, $subject->getId());

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW                       => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::Teacher),
            self::CREATE                     => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::UPDATE                     => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::DELETE                     => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::EXPORT_CSV                 => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::SCHOOL_PROFILES_EXPORT_CSV => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            default                          => false,
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
