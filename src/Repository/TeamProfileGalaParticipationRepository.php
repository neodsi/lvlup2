<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Event;
use App\Entity\TeamProfile;
use App\Entity\TeamProfileGalaParticipation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamProfileGalaParticipation>
 */
class TeamProfileGalaParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamProfileGalaParticipation::class);
    }

    public function findOneByTeamProfileAndEvent(
        TeamProfile $teamProfile,
        Event $event
    ): ?TeamProfileGalaParticipation {
        return $this->createQueryBuilder('tpgp')
            ->where('tpgp.teamProfile = :teamProfile')
            ->andWhere('tpgp.event = :event')
            ->setParameter('teamProfile', $teamProfile)
            ->setParameter('event', $event)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return TeamProfileGalaParticipation[]
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
