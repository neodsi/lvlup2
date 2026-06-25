<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use App\Entity\SchoolUser;
use App\Entity\SchoolProfileSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolProfileSeason>
 */
class SchoolProfileSeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolProfileSeason::class);
    }

    public function findOneBySchoolProfileAndSeason(
        SchoolUser $schoolUser,
        Season $season
    ): ?SchoolProfileSeason {
        return $this->createQueryBuilder('tps')
            ->where('tps.schoolProfile = :schoolProfile')
            ->andWhere('tps.season = :season')
            ->setParameter('schoolProfile', $schoolUser)
            ->setParameter('season', $season)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return SchoolProfileSeason[]
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
