<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Profile;
use App\Entity\Season;
use App\Entity\SchoolProfileSeason;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SchoolProfileSeason>
 */
class SchoolProfileSeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SchoolProfileSeason::class);
    }

    public function findOneByProfileAndSeason(Profile $profile, Season $season): ?SchoolProfileSeason
    {
        return $this->findOneBy([
            'profileId' => $profile->getId(),
            'seasonId'  => $season->getId(),
        ]);
    }

    /** @return SchoolProfileSeason[] */
    public function findBySchoolAndSeason(string $schoolId, string $seasonId): array
    {
        return $this->findBy(['schoolId' => $schoolId, 'seasonId' => $seasonId]);
    }
}
