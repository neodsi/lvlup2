<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupInvite;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupInvite>
 */
class GroupInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupInvite::class);
    }

    public function findOneByToken(string $token): ?GroupInvite
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * @return GroupInvite[]
     */
    public function findPendingBySchool(School $school): array
    {
        return $this->createQueryBuilder('gi')
            ->where('gi.school = :school')
            ->andWhere("gi.status = 'pending'")
            ->orderBy('gi.createdAt', 'DESC')
            ->setParameter('school', $school)
            ->getQuery()
            ->getResult();
    }
}
