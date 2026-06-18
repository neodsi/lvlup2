<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\School;
use App\Entity\SchoolHomeKpiDaily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolHomeKpiDaily>
 */
class SchoolHomeKpiDailyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolHomeKpiDaily::class);
    }

    public function findOneBySchoolAndDate(School $school, \DateTimeInterface $date): ?SchoolHomeKpiDaily
    {
        return $this->createQueryBuilder('kpi')
            ->where('kpi.school = :school')
            ->andWhere('kpi.date = :date')
            ->setParameter('school', $school)
            ->setParameter('date', $date)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return SchoolHomeKpiDaily[]
     */
    public function findRecentBySchool(School $school, int $days = 30): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('kpi')
            ->where('kpi.school = :school')
            ->andWhere('kpi.date >= :since')
            ->orderBy('kpi.date', 'DESC')
            ->setParameter('school', $school)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }
}
