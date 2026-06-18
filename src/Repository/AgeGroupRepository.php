<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AgeGroup;
use App\Entity\Season;
use App\Entity\School;
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
    public function findBySchoolAndSeason(School $school, Season $season): array
    {
        return $this->createQueryBuilder('ag')
            ->where('ag.school = :school')
            ->andWhere('ag.season = :season')
            ->andWhere('ag.deletedAt IS NULL')
            ->orderBy('ag.name', 'ASC')
            ->setParameter('school', $school)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
