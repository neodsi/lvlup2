<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\OrderItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderItem>
 */
class OrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }

    /**
     * @return OrderItem[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('oi')
            ->where('oi.order = :order')
            ->andWhere('oi.deletedAt IS NULL')
            ->setParameter('order', $order)
            ->getQuery()
            ->getResult();
    }
}
