<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriceModifier;
use App\Entity\Season;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceModifier>
 */
class PriceModifierRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceModifier::class);
    }

    /**
     * @return PriceModifier[]
     */
    public function findByTeamAndSeason(Team $team, Season $season): array
    {
        return $this->createQueryBuilder('pm')
            ->where('pm.team = :team')
            ->andWhere('pm.season = :season')
            ->andWhere('pm.deletedAt IS NULL')
            ->setParameter('team', $team)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
