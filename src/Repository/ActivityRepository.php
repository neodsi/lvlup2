<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\Season;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Activity>
 */
class ActivityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Activity::class);
    }

    /**
     * @return Activity[]
     */
    public function findBySchoolAndSeason(School $school, Season $season): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.school = :school')
            ->andWhere('a.season = :season')
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('a.name', 'ASC')
            ->setParameter('school', $school)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
