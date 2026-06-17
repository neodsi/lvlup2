<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailBounce;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailBounce>
 */
class EmailBounceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailBounce::class);
    }

    public function hasBouncedEmail(string $email): bool
    {
        $count = $this->createQueryBuilder('eb')
            ->select('COUNT(eb.id)')
            ->where('eb.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @return EmailBounce[]
     */
    public function findByEmail(string $email): array
    {
        return $this->findBy(['email' => $email], ['createdAt' => 'DESC']);
    }
}
