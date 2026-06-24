<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Entity\Event;
use App\Entity\Package;
use App\Entity\School;
use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SchoolPageController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{citySlug}/{schoolSlug}', name: 'app_school_page', methods: ['GET'], priority: -10,
        requirements: ['citySlug' => '[a-z0-9-]+', 'schoolSlug' => '[a-z0-9-]+'])]
    public function schoolPage(string $citySlug, string $schoolSlug): Response
    {
        $school = $this->em->getRepository(School::class)->findOneBy(['currentSlug' => $schoolSlug]);

        if ($school === null) {
            // Check previousSlugs for redirect
            $school = $this->em->createQueryBuilder()
                ->select('t')
                ->from(School::class, 't')
                ->where('JSON_CONTAINS(t.previousSlugs, :slug) = 1')
                ->setParameter('slug', json_encode($schoolSlug))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($school !== null) {
                return $this->redirectToRoute('app_school_page', [
                    'citySlug'   => $school->getCitySlug() ?? $citySlug,
                    'schoolSlug' => $school->getCurrentSlug(),
                ], Response::HTTP_MOVED_PERMANENTLY);
            }

            throw $this->createNotFoundException('École introuvable.');
        }

        $now = new \DateTimeImmutable();

        $seasons = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Season::class, 's')
            ->where('s.schoolId = :schoolId')
            ->andWhere('s.visible = true')
            ->andWhere('s.deletedAt IS NULL')
            ->andWhere('s.endAt >= :now')
            ->orderBy('s.startAt', 'ASC')
            ->setParameter('schoolId', $school->getId())
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();

        $seasonData = [];
        foreach ($seasons as $season) {
            $packages = $this->em->createQueryBuilder()
                ->select('p')
                ->from(Package::class, 'p')
                ->where('p.seasonId = :sid')
                ->andWhere('p.deletedAt IS NULL')
                ->orderBy('p.price', 'ASC')
                ->setParameter('sid', $season->getId())
                ->getQuery()
                ->getResult();

            $events = $this->em->createQueryBuilder()
                ->select('e')
                ->from(Event::class, 'e')
                ->where('e.seasonId = :sid')
                ->andWhere('e.deletedAt IS NULL')
                ->andWhere('e.visible = true')
                ->orderBy('e.name', 'ASC')
                ->setParameter('sid', $season->getId())
                ->getQuery()
                ->getResult();

            $preRegOpen = $season->getPreRegistrationsStartAt() !== null
                && $season->getPreRegistrationsStartAt() <= $now
                && ($season->getPreRegistrationsEndAt() === null || $season->getPreRegistrationsEndAt() >= $now);

            $regOpen = $season->getRegistrationsStartAt() !== null
                && $season->getRegistrationsStartAt() <= $now
                && ($season->getRegistrationsEndAt() === null || $season->getRegistrationsEndAt() >= $now);

            $seasonData[] = [
                'season'      => $season,
                'packages'    => $packages,
                'events'      => $events,
                'preRegOpen'  => $preRegOpen,
                'regOpen'     => $regOpen,
            ];
        }

        return $this->render('public/school.html.twig', [
            'school'     => $school,
            'seasonData' => $seasonData,
        ]);
    }
}
