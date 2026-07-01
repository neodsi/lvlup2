<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\Profile;
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

    public function findOneByProfileAndEvent(Profile $profile, Event $event): ?SchoolProfileGalaParticipation
    {
        return $this->findOneBy([
            'profileId' => $profile->getId(),
            'eventId'   => $event->getId(),
        ]);
    }

    /** @return SchoolProfileGalaParticipation[] */
    public function findByEvent(Event $event): array
    {
        return $this->findBy(['eventId' => $event->getId()]);
    }
}
