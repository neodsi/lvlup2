<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamProfile;
use App\Entity\TeamProfileSeason;
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

    #[Route('/shop/{teamSlug}', name: 'app_shop', methods: ['GET'])]
    public function shop(string $teamSlug, Request $request): Response
    {
        return $this->handleShopRequest($teamSlug, $request, 'app_shop', 'shop/shop.html.twig');
    }

    #[Route('/iframes/shop/{teamSlug}', name: 'app_shop_iframe', methods: ['GET'])]
    public function iframe(string $teamSlug, Request $request): Response
    {
        return $this->handleShopRequest($teamSlug, $request, 'app_shop_iframe', 'shop/iframe.html.twig');
    }

    private function handleShopRequest(
        string $teamSlug,
        Request $request,
        string $routeName,
        string $template,
    ): Response {
        // 1. Load team by currentSlug, fall back to previousSlugs for old URLs.
        $team = $this->em->getRepository(Team::class)->findOneBy(['currentSlug' => $teamSlug]);

        if ($team === null) {
            // Search previousSlugs (JSON column): find any team that contains the slug.
            $team = $this->em->createQueryBuilder()
                ->select('t')
                ->from(Team::class, 't')
                ->where('JSON_CONTAINS(t.previousSlugs, :slug) = 1')
                ->setParameter('slug', json_encode($teamSlug))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($team !== null) {
                // Permanent redirect to the current canonical slug.
                return $this->redirectToRoute(
                    $routeName,
                    ['teamSlug' => $team->getCurrentSlug()],
                    Response::HTTP_MOVED_PERMANENTLY
                );
            }

            throw $this->createNotFoundException('School not found.');
        }

        // 2. Resolve the season to display.
        $season = $this->resolveSeason($team, $request->query->get('seasonId'));

        // 3. Load events and packages for the season.
        $events   = [];
        $packages = [];

        if ($season !== null) {
            $events   = $season->getEvents()->filter(fn ($e) => $e->getDeletedAt() === null)->toArray();
            $packages = $season->getPackages()->filter(fn ($p) => $p->getDeletedAt() === null)->toArray();
        }

        // 4. If user is logged in: load their TeamProfile and registration info.
        /** @var User|null $user */
        $user        = $this->getUser();
        $teamProfile = null;
        $teamProfileSeason = null;

        if ($user !== null && $season !== null) {
            // Load the TeamProfile that belongs to this user for this team.
            $teamProfile = $this->em->createQueryBuilder()
                ->select('tp')
                ->from(TeamProfile::class, 'tp')
                ->join('tp.profile', 'p')
                ->where('p.userId = :userId')
                ->andWhere('tp.team = :team')
                ->andWhere('tp.deletedAt IS NULL')
                ->setParameter('userId', $user->getId())
                ->setParameter('team', $team)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($teamProfile !== null) {
                $teamProfileSeason = $this->em->getRepository(TeamProfileSeason::class)
                    ->findOneBy([
                        'teamProfileId' => $teamProfile->getId(),
                        'seasonId'      => $season->getId(),
                    ]);
            }
        }

        return $this->render($template, [
            'team'               => $team,
            'season'             => $season,
            'events'             => $events,
            'packages'           => $packages,
            'teamProfile'        => $teamProfile,
            'teamProfileSeason'  => $teamProfileSeason,
        ]);
    }

    /**
     * Resolves the season to display:
     * 1. If ?seasonId is provided, use it.
     * 2. Otherwise use the team's currentSeasonId.
     * 3. If that season is past, fall back to the next open (future) season.
     */
    private function resolveSeason(Team $team, ?string $seasonId): ?Season
    {
        if ($seasonId !== null) {
            return $this->em->getRepository(Season::class)->find($seasonId);
        }

        $currentSeasonId = $team->getCurrentSeasonId();

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
                        ->where('s.teamId = :teamId')
                        ->andWhere('s.startAt > :now')
                        ->andWhere('s.deletedAt IS NULL')
                        ->orderBy('s.startAt', 'ASC')
                        ->setMaxResults(1)
                        ->setParameter('teamId', $team->getId())
                        ->setParameter('now', $now)
                        ->getQuery()
                        ->getOneOrNullResult();

                    return $nextSeason ?? $season;
                }

                return $season;
            }
        }

        // No current season configured: return the most recent non-deleted season for the team.
        return $this->em->createQueryBuilder()
            ->select('s')
            ->from(Season::class, 's')
            ->where('s.teamId = :teamId')
            ->andWhere('s.deletedAt IS NULL')
            ->orderBy('s.startAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('teamId', $team->getId())
            ->getQuery()
            ->getOneOrNullResult();
    }
}
