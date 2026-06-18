<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EventOccurence;
use App\Entity\EventOccurenceProfile;
use App\Entity\SchoolProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EventOccurenceProfile>
 */
class EventOccurenceProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventOccurenceProfile::class);
    }

    public function findOneByOccurenceAndSchoolProfile(
        EventOccurence $occurence,
        SchoolProfile $schoolProfile
    ): ?EventOccurenceProfile {
        return $this->createQueryBuilder('eop')
            ->where('eop.eventOccurence = :occurence')
            ->andWhere('eop.schoolProfile = :schoolProfile')
            ->setParameter('occurence', $occurence)
            ->setParameter('schoolProfile', $schoolProfile)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return EventOccurenceProfile[]
     */
    public function findByOccurence(EventOccurence $occurence): array
    {
        return $this->createQueryBuilder('eop')
            ->where('eop.eventOccurence = :occurence')
            ->setParameter('occurence', $occurence)
            ->getQuery()
            ->getResult();
    }
}
