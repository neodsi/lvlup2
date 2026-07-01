<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\SchoolRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class SchoolContextService
{
    private const SESSION_KEY = 'currentSchoolId';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function getCurrentSchoolId(): ?string
    {
        $session = $this->requestStack->getSession();

        /** @var string|null $schoolId */
        $schoolId = $session->get(self::SESSION_KEY);

        return $schoolId ?: null;
    }

    public function setCurrentSchoolId(string $schoolId, Request $request): void
    {
        $request->getSession()->set(self::SESSION_KEY, $schoolId);
    }

    /**
     * Returns the current user's SchoolProfileSeason (most recent) for the current school.
     * Returns null if the user has no membership AND is not the school owner.
     */
    public function getCurrentSchoolMember(User $user): ?SchoolProfileSeason
    {
        $schoolId = $this->getCurrentSchoolId();
        if ($schoolId === null) {
            return null;
        }

        $profile = $user->getProfile();
        if ($profile === null) {
            // If the user is the school owner (set on school creation), still grant access
            $school = $this->em->getRepository(School::class)->find($schoolId);
            if ($school !== null && $school->getOwnerProfileId() === null) {
                // No profile, no ownership — no access
            }
            return null;
        }

        // Find the most relevant SchoolProfileSeason (current season preferred)
        $school = $this->em->getRepository(School::class)->find($schoolId);

        if ($school !== null && $school->getCurrentSeasonId() !== null) {
            $sps = $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'profileId' => $profile->getId(),
                'schoolId'  => $schoolId,
                'seasonId'  => $school->getCurrentSeasonId(),
            ]);
            if ($sps !== null) {
                return $sps;
            }
        }

        // Fall back to any season
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

        if ($sps !== null) {
            return $sps;
        }

        // Owner without any SchoolProfileSeason yet (school just created, no season)
        if ($school !== null && $school->getOwnerProfileId() !== null && $school->getOwnerProfileId() === $profile->getId()) {
            $synthetic = new SchoolProfileSeason();
            $synthetic->setRole(SchoolRole::School);
            $synthetic->setSchoolId($school->getId());
            $synthetic->setProfileId($profile->getId());
            return $synthetic;
        }

        return null;
    }

    /**
     * @deprecated Use getCurrentSchoolMember() instead
     */
    public function getCurrentSchoolUser(User $user): ?SchoolProfileSeason
    {
        return $this->getCurrentSchoolMember($user);
    }

    public function getCurrentSchool(): ?School
    {
        $schoolId = $this->getCurrentSchoolId();

        if ($schoolId !== null) {
            return $this->em->getRepository(School::class)->find($schoolId);
        }

        // Auto-detect from the current authenticated user
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        $profile = $user->getProfile();
        if ($profile === null) {
            return null;
        }

        // Check schools owned by this user
        $school = $this->em->createQueryBuilder()
            ->select('s')
            ->from(School::class, 's')
            ->where('s.ownerProfileId = :profileId')
            ->andWhere('s.deletedAt IS NULL')
            ->setMaxResults(1)
            ->setParameter('profileId', $profile->getId())
            ->getQuery()
            ->getOneOrNullResult();

        if ($school !== null) {
            $this->requestStack->getSession()->set(self::SESSION_KEY, $school->getId());
            return $school;
        }

        // Otherwise find via most recent SchoolProfileSeason
        $sps = $this->em->createQueryBuilder()
            ->select('sps')
            ->from(SchoolProfileSeason::class, 'sps')
            ->where('sps.profileId = :profileId')
            ->orderBy('sps.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('profileId', $profile->getId())
            ->getQuery()
            ->getOneOrNullResult();

        if ($sps !== null) {
            $this->requestStack->getSession()->set(self::SESSION_KEY, $sps->getSchoolId());
            return $this->em->getRepository(School::class)->find($sps->getSchoolId());
        }

        return null;
    }
}
