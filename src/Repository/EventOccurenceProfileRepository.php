<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EventOccurence;
use App\Entity\EventOccurenceProfile;
use App\Entity\Profile;
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

    public function findOneByOccurenceAndProfile(EventOccurence $occurence, Profile $profile): ?EventOccurenceProfile
    {
        return $this->findOneBy([
            'eventOccurenceId' => $occurence->getId(),
            'profileId'        => $profile->getId(),
        ]);
    }

    /** @return EventOccurenceProfile[] */
    public function findByOccurence(EventOccurence $occurence): array
    {
        return $this->findBy(['eventOccurenceId' => $occurence->getId()]);
    }
}
