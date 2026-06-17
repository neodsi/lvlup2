<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use App\Entity\Team;
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
    public function findByTeam(Team $team): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.team = :team')
            ->andWhere('s.deletedAt IS NULL')
            ->orderBy('s.startAt', 'DESC')
            ->setParameter('team', $team)
            ->getQuery()
            ->getResult();
    }
}
