<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\SchoolUser;
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
    public function findBySchoolProfileAndSeason(SchoolUser $schoolUser, Season $season): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.schoolProfile = :schoolProfile')
            ->andWhere('o.season = :season')
            ->andWhere('o.deletedAt IS NULL')
            ->orderBy('o.createdAt', 'DESC')
            ->setParameter('schoolProfile', $schoolUser)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
