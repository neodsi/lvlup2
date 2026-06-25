<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\SchoolUser;
use App\Entity\SchoolProfileGalaParticipation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolProfileGalaParticipation>
 */
class SchoolProfileGalaParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolProfileGalaParticipation::class);
    }

    public function findOneBySchoolProfileAndEvent(
        SchoolUser $schoolUser,
        Event $event
    ): ?SchoolProfileGalaParticipation {
        return $this->createQueryBuilder('tpgp')
            ->where('tpgp.schoolProfile = :schoolProfile')
            ->andWhere('tpgp.event = :event')
            ->setParameter('schoolProfile', $schoolUser)
            ->setParameter('event', $event)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return SchoolProfileGalaParticipation[]
     */
    public function findByEvent(Event $event): array
    {
        return $this->createQueryBuilder('tpgp')
            ->where('tpgp.event = :event')
            ->setParameter('event', $event)
            ->getQuery()
            ->getResult();
    }
}
