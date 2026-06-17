<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Activity;
use App\Entity\Season;
use App\Entity\Team;
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
    public function findByTeamAndSeason(Team $team, Season $season): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.team = :team')
            ->andWhere('a.season = :season')
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('a.name', 'ASC')
            ->setParameter('team', $team)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
