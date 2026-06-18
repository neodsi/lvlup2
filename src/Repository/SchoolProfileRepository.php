<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchoolProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolProfile>
 */
class SchoolProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolProfile::class);
    }

    /**
     * Finds the active (non-deleted) SchoolProfile for a given user and school.
     *
     * The chain is: User -> Profile (userId) -> SchoolProfile (profile_id + team_id).
     * A user may have multiple profiles; we match any profile owned by this user.
     */
    public function findOneByUserAndSchool(User $user, string $schoolId): ?SchoolProfile
    {
        return $this->createQueryBuilder('tp')
            ->join('tp.profile', 'p')
            ->where('p.user = :user')
            ->andWhere('tp.school = :schoolId')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('schoolId', $schoolId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
