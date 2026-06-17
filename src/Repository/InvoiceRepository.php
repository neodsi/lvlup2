<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * @return Invoice[]
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.order = :order')
            ->orderBy('i.invoiceDate', 'DESC')
            ->setParameter('order', $order)
            ->getQuery()
            ->getResult();
    }

    public function findOneByInvoiceNumber(string $invoiceNumber): ?Invoice
    {
        return $this->findOneBy(['invoiceNumber' => $invoiceNumber]);
    }
}
