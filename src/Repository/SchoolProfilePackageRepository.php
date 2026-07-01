<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Profile;
use App\Entity\SchoolProfilePackage;
use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolProfilePackage>
 */
class SchoolProfilePackageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolProfilePackage::class);
    }

    /** @return SchoolProfilePackage[] */
    public function findByProfileAndSeason(Profile $profile, Season $season): array
    {
        return $this->findBy([
            'profileId' => $profile->getId(),
            'seasonId'  => $season->getId(),
            'deletedAt' => null,
        ]);
    }
}
