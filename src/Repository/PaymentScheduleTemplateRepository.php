<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PaymentScheduleTemplate;
use App\Entity\Season;
use App\Entity\Team;
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
    public function findByTeamAndSeason(Team $team, Season $season): array
    {
        return $this->createQueryBuilder('pst')
            ->where('pst.team = :team')
            ->andWhere('pst.season = :season')
            ->andWhere('pst.deletedAt IS NULL')
            ->setParameter('team', $team)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
