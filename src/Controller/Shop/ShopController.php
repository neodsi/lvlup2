<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\Season;
use App\Entity\School;
use App\Entity\SchoolProfile;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ShopController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/shop/{schoolSlug}', name: 'app_shop', methods: ['GET'])]
    public function shop(string $schoolSlug, Request $request): Response
    {
        return $this->handleShopRequest($schoolSlug, $request, 'app_shop', 'shop/shop.html.twig');
    }

    #[Route('/iframes/shop/{schoolSlug}', name: 'app_shop_iframe', methods: ['GET'])]
    public function iframe(string $schoolSlug, Request $request): Response
    {
        return $this->handleShopRequest($schoolSlug, $request, 'app_shop_iframe', 'shop/iframe.html.twig');
    }

    private function handleShopRequest(
        string $schoolSlug,
        Request $request,
        string $routeName,
        string $template,
    ): Response {
        // 1. Load school by currentSlug, fall back to previousSlugs for old URLs.
        $school = $this->em->getRepository(School::class)->findOneBy(['currentSlug' => $schoolSlug]);

        if ($school === null) {
            // Search previousSlugs (JSON column): find any school that contains the slug.
            $school = $this->em->createQueryBuilder()
                ->select('t')
                ->from(School::class, 't')
                ->where('JSON_CONTAINS(t.previousSlugs, :slug) = 1')
                ->setParameter('slug', json_encode($schoolSlug))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($school !== null) {
                // Permanent redirect to the current canonical slug.
                return $this->redirectToRoute(
                    $routeName,
                    ['schoolSlug' => $school->getCurrentSlug()],
                    Response::HTTP_MOVED_PERMANENTLY
                );
            }

            throw $this->createNotFoundException('School not found.');
        }

        // 2. Resolve the season to display.
        $season = $this->resolveSeason($school, $request->query->get('seasonId'));

        // 3. Load events and packages for the season.
        $events   = [];
        $packages = [];

        if ($season !== null) {
            $events   = $season->getEvents()->filter(fn ($e) => $e->getDeletedAt() === null)->toArray();
            $packages = $season->getPackages()->filter(fn ($p) => $p->getDeletedAt() === null)->toArray();
        }

        // 4. If user is logged in: load their SchoolProfile and registration info.
        /** @var User|null $user */
        $user        = $this->getUser();
        $schoolProfile = null;
        $schoolProfileSeason = null;

        if ($user !== null && $season !== null) {
            // Load the SchoolProfile that belongs to this user for this school.
            $schoolProfile = $this->em->createQueryBuilder()
                ->select('tp')
                ->from(SchoolProfile::class, 'tp')
                ->join('tp.profile', 'p')
                ->where('p.user = :user')
                ->andWhere('tp.school = :school')
                ->andWhere('tp.deletedAt IS NULL')
                ->setParameter('user', $user)
                ->setParameter('school', $school)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($schoolProfile !== null) {
                $schoolProfileSeason = $this->em->getRepository(SchoolProfileSeason::class)
                    ->findOneBy([
                        'schoolProfileId' => $schoolProfile->getId(),
                        'seasonId'      => $season->getId(),
                    ]);
            }
        }

        return $this->render($template, [
            'school'               => $school,
            'season'             => $season,
            'events'             => $events,
            'packages'           => $packages,
            'schoolProfile'        => $schoolProfile,
            'schoolProfileSeason'  => $schoolProfileSeason,
        ]);
    }

    /**
     * Resolves the season to display:
     * 1. If ?seasonId is provided, use it.
     * 2. Otherwise use the school's currentSeasonId.
     * 3. If that season is past, fall back to the next open (future) season.
     */
    private function resolveSeason(School $school, ?string $seasonId): ?Season
    {
        if ($seasonId !== null) {
            return $this->em->getRepository(Season::class)->find($seasonId);
        }

        $currentSeasonId = $school->getCurrentSeasonId();

        if ($currentSeasonId !== null) {
            /** @var Season|null $season */
            $season = $this->em->getRepository(Season::class)->find($currentSeasonId);

            if ($season !== null) {
                $now = new \DateTimeImmutable();

                // If the current season has ended, try to find the next open season.
                if ($season->getEndAt() < $now) {
                    $nextSeason = $this->em->createQueryBuilder()
                        ->select('s')
                        ->from(Season::class, 's')
                        ->where('s.schoolId = :schoolId')
                        ->andWhere('s.startAt > :now')
                        ->andWhere('s.deletedAt IS NULL')
                        ->orderBy('s.startAt', 'ASC')
                        ->setMaxResults(1)
                        ->setParameter('schoolId', $school->getId())
                        ->setParameter('now', $now)
                        ->getQuery()
                        ->getOneOrNullResult();

                    return $nextSeason ?? $season;
                }

                return $season;
            }
        }

        // No current season configured: return the most recent non-deleted season for the school.
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(Season::class, 's')
            ->where('s.schoolId = :schoolId')
            ->andWhere('s.deletedAt IS NULL')
            ->orderBy('s.startAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('schoolId', $school->getId())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
