<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TeamProfile;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamProfile>
 */
class TeamProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamProfile::class);
    }

    /**
     * Finds the active (non-deleted) TeamProfile for a given user and team.
     *
     * The chain is: User -> Profile (userId) -> TeamProfile (profile_id + team_id).
     * A user may have multiple profiles; we match any profile owned by this user.
     */
    public function findOneByUserAndTeam(User $user, string $teamId): ?TeamProfile
    {
        return $this->createQueryBuilder('tp')
            ->join('tp.profile', 'p')
            ->where('p.userId = :userId')
            ->andWhere('tp.team = :teamId')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('userId', $user->getId())
            ->setParameter('teamId', $teamId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
