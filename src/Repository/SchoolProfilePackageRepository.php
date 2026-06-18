<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SchoolProfile;
use App\Entity\SchoolProfilePackage;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolProfilePackage>
 */
class SchoolProfilePackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolProfilePackage::class);
    }

    /**
     * @return SchoolProfilePackage[]
     */
    public function findBySchoolProfileAndSeason(SchoolProfile $schoolProfile, Season $season): array
    {
        return $this->createQueryBuilder('tpp')
            ->where('tpp.schoolProfile = :schoolProfile')
            ->andWhere('tpp.season = :season')
            ->andWhere('tpp.deletedAt IS NULL')
            ->setParameter('schoolProfile', $schoolProfile)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
