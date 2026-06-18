<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Package;
use App\Entity\Season;
use App\Entity\School;
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
    public function findBySchoolAndSeason(School $school, Season $season): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.school = :school')
            ->andWhere('p.season = :season')
            ->andWhere('p.deletedAt IS NULL')
            ->orderBy('p.name', 'ASC')
            ->setParameter('school', $school)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
