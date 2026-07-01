<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\School;
use App\Entity\SchoolProfileSeason;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Security\SchoolRoleHierarchy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions:
 *   seasons:view   – any school member
 *   seasons:create – admin+
 *   seasons:update – admin+
 *   seasons:delete – admin+
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
        private readonly EntityManagerInterface $em,
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

        $schoolRole = $this->resolveSchoolRole($user, $season->getSchoolId());

        if ($schoolRole === null) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => true,
            self::CREATE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::UPDATE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
            self::DELETE => SchoolRoleHierarchy::isGranted($schoolRole, SchoolRole::School),
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
