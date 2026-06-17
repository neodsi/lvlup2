<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IntentOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IntentOrder>
 */
class IntentOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntentOrder::class);
    }

    public function findOneByStripeCheckoutSession(string $sessionId): ?IntentOrder
    {
        return $this->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
    }

    public function findOnePendingById(string $id): ?IntentOrder
    {
        return $this->createQueryBuilder('io')
            ->where('io.id = :id')
            ->andWhere("io.status = 'pending'")
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
