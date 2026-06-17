<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use App\Entity\TeamProfile;
use App\Entity\TeamProfileSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamProfileSeason>
 */
class TeamProfileSeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamProfileSeason::class);
    }

    public function findOneByTeamProfileAndSeason(
        TeamProfile $teamProfile,
        Season $season
    ): ?TeamProfileSeason {
        return $this->createQueryBuilder('tps')
            ->where('tps.teamProfile = :teamProfile')
            ->andWhere('tps.season = :season')
            ->setParameter('teamProfile', $teamProfile)
            ->setParameter('season', $season)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return TeamProfileSeason[]
     */
    public function findBySeasonWithRegistered(Season $season): array
    {
        return $this->createQueryBuilder('tps')
            ->where('tps.season = :season')
            ->andWhere("tps.registrationStatus != 'not_registered'")
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
