<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\EventOccurence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventOccurence>
 */
class EventOccurenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventOccurence::class);
    }

    /**
     * @return EventOccurence[]
     */
    public function findByEvent(Event $event): array
    {
        return $this->createQueryBuilder('eo')
            ->where('eo.event = :event')
            ->andWhere('eo.deletedAt IS NULL')
            ->orderBy('eo.occurenceAt', 'ASC')
            ->setParameter('event', $event)
            ->getQuery()
            ->getResult();
    }

    public function findOneByEventAndDate(Event $event, \DateTimeInterface $date): ?EventOccurence
    {
        return $this->createQueryBuilder('eo')
            ->where('eo.event = :event')
            ->andWhere('eo.occurenceAt = :date')
            ->andWhere('eo.deletedAt IS NULL')
            ->setParameter('event', $event)
            ->setParameter('date', $date)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
