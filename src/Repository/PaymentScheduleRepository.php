<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\PaymentSchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentSchedule>
 */
class PaymentScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentSchedule::class);
    }

    /**
     * @return PaymentSchedule[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('ps')
            ->where('ps.order = :order')
            ->orderBy('ps.dueAt', 'ASC')
            ->setParameter('order', $order)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PaymentSchedule[]
     */
    public function findPendingDueOnOrBefore(\DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('ps')
            ->where("ps.status = 'pending'")
            ->andWhere('ps.dueAt <= :date')
            ->orderBy('ps.dueAt', 'ASC')
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }
}
