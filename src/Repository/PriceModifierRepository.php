<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PriceModifier;
use App\Entity\Season;
use App\Entity\School;
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
    public function findBySchoolAndSeason(School $school, Season $season): array
    {
        return $this->createQueryBuilder('pm')
            ->where('pm.school = :school')
            ->andWhere('pm.season = :season')
            ->andWhere('pm.deletedAt IS NULL')
            ->setParameter('school', $school)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
