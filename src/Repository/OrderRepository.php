<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\Profile;
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

    /** @return Order[] */
    public function findByProfileAndSeason(Profile $profile, Season $season): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.profileId = :profileId')
            ->andWhere('o.seasonId = :seasonId')
            ->andWhere('o.deletedAt IS NULL')
            ->orderBy('o.createdAt', 'DESC')
            ->setParameter('profileId', $profile->getId())
            ->setParameter('seasonId', $season->getId())
            ->getQuery()
            ->getResult();
    }
}
