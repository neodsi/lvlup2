<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Address;
use App\Entity\Season;
use App\Entity\School;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Address>
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * @return Address[]
     */
    public function findBySchoolAndSeason(School $school, Season $season): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.school = :school')
            ->andWhere('a.season = :season')
            ->andWhere('a.deletedAt IS NULL')
            ->orderBy('a.name', 'ASC')
            ->setParameter('school', $school)
            ->setParameter('season', $season)
            ->getQuery()
            ->getResult();
    }
}
