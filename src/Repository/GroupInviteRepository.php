<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GroupInvite;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupInvite>
 */
class GroupInviteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupInvite::class);
    }

    public function findOneByToken(string $token): ?GroupInvite
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * @return GroupInvite[]
     */
    public function findPendingByTeam(Team $team): array
    {
        return $this->createQueryBuilder('gi')
            ->where('gi.team = :team')
            ->andWhere("gi.status = 'pending'")
            ->orderBy('gi.createdAt', 'DESC')
            ->setParameter('team', $team)
            ->getQuery()
            ->getResult();
    }
}
