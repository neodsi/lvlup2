<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeamProfile;
use App\Entity\TeamProfilePackage;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamProfilePackage>
 */
class TeamProfilePackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamProfilePackage::class);
    }

    /**
     * @return TeamProfilePackage[]
     */
    public function findByTeamProfileAndSeason(TeamProfile $teamProfile, Season $season): array
    {
        return $this->createQueryBuilder('tpp')
            ->where('tpp.teamProfile = :teamProfile')
            ->andWhere('tpp.season = :season')
            ->andWhere('tpp.deletedAt IS NULL')
            ->setParameter('teamProfile', $teamProfile)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
