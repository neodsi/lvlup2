<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Package;
use App\Entity\Season;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Package>
 */
class PackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Package::class);
    }

    /**
     * @return Package[]
     */
    public function findByTeamAndSeason(Team $team, Season $season): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.team = :team')
            ->andWhere('p.season = :season')
            ->andWhere('p.deletedAt IS NULL')
            ->orderBy('p.name', 'ASC')
            ->setParameter('team', $team)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
