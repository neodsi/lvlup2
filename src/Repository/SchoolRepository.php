<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\School;
use App\Enum\SchoolStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<School>
 */
class SchoolRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, School::class);
    }

    public function findOneBySlug(string $slug): ?School
    {
        return $this->createQueryBuilder('t')
            ->where('t.currentSlug = :slug')
            ->andWhere('t.deletedAt IS NULL')
            ->setParameter('slug', $slug)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForDirectory(string $q = '', int $page = 1, int $perPage = 12): array
    {
        $qb = $this->basePublicQb();

        if ($q !== '') {
            $qb->andWhere('s.name LIKE :q OR s.citySlug LIKE :qCity')
               ->setParameter('q', '%' . $q . '%')
               ->setParameter('qCity', '%' . str_replace(' ', '-', mb_strtolower($q)) . '%');
        }

        $total = (int) (clone $qb)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $results = (clone $qb)
            ->select('s')
            ->orderBy('s.name', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $mapData = (clone $qb)
            ->select('s')
            ->andWhere('s.addressLat IS NOT NULL')
            ->andWhere('s.addressLng IS NOT NULL')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return ['results' => $results, 'total' => $total, 'mapData' => $mapData];
    }

    public function findByCitySlug(string $citySlug, int $page = 1, int $perPage = 12): array
    {
        $qb = $this->basePublicQb()
            ->andWhere('s.citySlug = :citySlug')
            ->setParameter('citySlug', $citySlug);

        $total = (int) (clone $qb)
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $results = (clone $qb)
            ->select('s')
            ->orderBy('s.name', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $mapData = (clone $qb)
            ->select('s')
            ->andWhere('s.addressLat IS NOT NULL')
            ->andWhere('s.addressLng IS NOT NULL')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return ['results' => $results, 'total' => $total, 'mapData' => $mapData];
    }

    private function basePublicQb(): QueryBuilder
    {
        return $this->createQueryBuilder('s')
            ->where('s.deletedAt IS NULL')
            ->andWhere('s.status = :status')
            ->andWhere('s.currentSlug IS NOT NULL')
            ->setParameter('status', SchoolStatus::Accepted);
    }
}
