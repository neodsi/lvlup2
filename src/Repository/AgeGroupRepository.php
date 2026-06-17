<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgeGroup;
use App\Entity\Season;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgeGroup>
 */
class AgeGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgeGroup::class);
    }

    /**
     * @return AgeGroup[]
     */
    public function findByTeamAndSeason(Team $team, Season $season): array
    {
        return $this->createQueryBuilder('ag')
            ->where('ag.team = :team')
            ->andWhere('ag.season = :season')
            ->andWhere('ag.deletedAt IS NULL')
            ->orderBy('ag.name', 'ASC')
            ->setParameter('team', $team)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
