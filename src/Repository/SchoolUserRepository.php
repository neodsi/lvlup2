<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchoolUser;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolUser>
 */
class SchoolUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolUser::class);
    }

    public function findOneByUserAndSchool(User $user, string $schoolId): ?SchoolUser
    {
        return $this->createQueryBuilder('su')
            ->where('su.user = :user')
            ->andWhere('su.school = :schoolId')
            ->andWhere('su.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('schoolId', $schoolId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
