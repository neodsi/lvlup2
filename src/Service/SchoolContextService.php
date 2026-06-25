<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\School;
use App\Entity\SchoolUser;
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

    public function getCurrentSchoolUser(User $user): ?SchoolUser
    {
        $schoolId = $this->getCurrentSchoolId();

        $qb = $this->em->createQueryBuilder()
            ->select('su', 't')
            ->from(SchoolUser::class, 'su')
            ->join('su.school', 't')
            ->where('su.user = :user')
            ->andWhere('su.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setMaxResults(1);

        if ($schoolId !== null) {
            $qb->andWhere('su.school = :schoolId')->setParameter('schoolId', $schoolId);
        }

        $su = $qb->getQuery()->getOneOrNullResult();

        if ($su !== null && $schoolId === null) {
            $this->requestStack->getSession()->set(self::SESSION_KEY, (string) $su->getSchool()->getId());
        }

        return $su;
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
            return $this->getCurrentSchoolUser($user)?->getSchool();
        }

        return null;
    }
}
