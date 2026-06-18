<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolProfile;
use App\Entity\User;
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

    public function getCurrentSchoolProfile(User $user): ?SchoolProfile
    {
        $profiles = $user->getProfiles()->filter(fn ($p) => $p->getDeletedAt() === null);

        if ($profiles->isEmpty()) {
            return null;
        }

        $profileIds = $profiles->map(fn ($p) => $p->getId())->toArray();
        $schoolId     = $this->getCurrentSchoolId();

        $qb = $this->em->createQueryBuilder()
            ->select('tp', 't')
            ->from(SchoolProfile::class, 'tp')
            ->join('tp.school', 't')
            ->join('tp.profile', 'p')
            ->where('p.id IN (:profileIds)')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('profileIds', $profileIds)
            ->setMaxResults(1);

        if ($schoolId !== null) {
            $qb->andWhere('tp.school = :schoolId')->setParameter('schoolId', $schoolId);
        }

        /** @var SchoolProfile|null $tp */
        $tp = $qb->getQuery()->getOneOrNullResult();

        if ($tp !== null && $schoolId === null) {
            $this->requestStack->getSession()->set(self::SESSION_KEY, (string) $tp->getSchool()->getId());
        }

        return $tp;
    }

    public function getCurrentSchool(): ?School
    {
        $schoolId = $this->getCurrentSchoolId();

        if ($schoolId !== null) {
            return $this->em->getRepository(School::class)->find($schoolId);
        }

        // Auto-detect from the current authenticated user (single-school workflow)
        $user = $this->security->getUser();
        if ($user instanceof User) {
            return $this->getCurrentSchoolProfile($user)?->getSchool();
        }

        return null;
    }
}
