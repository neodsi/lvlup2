<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentScheduleTemplate;
use App\Entity\Season;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentScheduleTemplate>
 */
class PaymentScheduleTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentScheduleTemplate::class);
    }

    /**
     * @return PaymentScheduleTemplate[]
     */
    public function findBySchoolAndSeason(School $school, Season $season): array
    {
        return $this->createQueryBuilder('pst')
            ->where('pst.school = :school')
            ->andWhere('pst.season = :season')
            ->andWhere('pst.deletedAt IS NULL')
            ->setParameter('school', $school)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
