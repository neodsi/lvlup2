<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * @return Payment[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.order = :order')
            ->orderBy('p.createdAt', 'DESC')
            ->setParameter('order', $order)
            ->getQuery()
            ->getResult();
    }

    public function findOneByStripePaymentIntent(string $intentId): ?Payment
    {
        return $this->findOneBy(['stripePaymentIntentId' => $intentId]);
    }

    public function findOneByStripeCheckoutSession(string $sessionId): ?Payment
    {
        return $this->findOneBy(['stripeCheckoutSessionId' => $sessionId]);
    }
}
