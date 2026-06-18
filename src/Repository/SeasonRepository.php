<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Season>
 */
class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }

    /**
     * @return Season[]
     */
    public function findBySchool(School $school): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.school = :school')
            ->andWhere('s.deletedAt IS NULL')
            ->orderBy('s.startAt', 'DESC')
            ->setParameter('school', $school)
            ->getQuery()
            ->getResult();
    }
}
