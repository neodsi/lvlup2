<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Room;
use App\Entity\Season;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Room>
 */
class RoomRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Room::class);
    }

    /**
     * @return Room[]
     */
    public function findBySchoolAndSeason(School $school, Season $season): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.school = :school')
            ->andWhere('r.season = :season')
            ->andWhere('r.deletedAt IS NULL')
            ->orderBy('r.name', 'ASC')
            ->setParameter('school', $school)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
