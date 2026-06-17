<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\TeamProfile;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * @return Order[]
     */
    public function findByTeamProfileAndSeason(TeamProfile $teamProfile, Season $season): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.teamProfile = :teamProfile')
            ->andWhere('o.season = :season')
            ->andWhere('o.deletedAt IS NULL')
            ->orderBy('o.createdAt', 'DESC')
            ->setParameter('teamProfile', $teamProfile)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
