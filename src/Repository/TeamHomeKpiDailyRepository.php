<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use App\Entity\TeamHomeKpiDaily;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamHomeKpiDaily>
 */
class TeamHomeKpiDailyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamHomeKpiDaily::class);
    }

    public function findOneByTeamAndDate(Team $team, \DateTimeInterface $date): ?TeamHomeKpiDaily
    {
        return $this->createQueryBuilder('kpi')
            ->where('kpi.team = :team')
            ->andWhere('kpi.date = :date')
            ->setParameter('team', $team)
            ->setParameter('date', $date)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return TeamHomeKpiDaily[]
     */
    public function findRecentByTeam(Team $team, int $days = 30): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('kpi')
            ->where('kpi.team = :team')
            ->andWhere('kpi.date >= :since')
            ->orderBy('kpi.date', 'DESC')
            ->setParameter('team', $team)
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }
}
