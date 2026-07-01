<?php

declare(strict_types=1);

namespace App\Controller\Shop;

use App\Entity\Season;
use App\Entity\School;
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
        $school = $this->em->getRepository(School::class)->findOneBy(['currentSlug' => $schoolSlug]);

        if ($school === null) {
            $school = $this->em->createQueryBuilder()
                ->select('t')
                ->from(School::class, 't')
                ->where('JSON_CONTAINS(t.previousSlugs, :slug) = 1')
                ->setParameter('slug', json_encode($schoolSlug))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($school !== null) {
                return $this->redirectToRoute(
                    $routeName,
                    ['schoolSlug' => $school->getCurrentSlug()],
                    Response::HTTP_MOVED_PERMANENTLY
                );
            }

            throw $this->createNotFoundException('School not found.');
        }

        $season = $this->resolveSeason($school, $request->query->get('seasonId'));

        $events   = [];
        $packages = [];

        if ($season !== null) {
            $events   = $season->getEvents()->filter(fn ($e) => $e->getDeletedAt() === null)->toArray();
            $packages = $season->getPackages()->filter(fn ($p) => $p->getDeletedAt() === null)->toArray();
        }

        /** @var User|null $user */
        $user                = $this->getUser();
        $schoolProfileSeason = null;

        if ($user !== null && $season !== null) {
            $profile = $user->getProfile();
            if ($profile !== null) {
                $schoolProfileSeason = $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                    'profileId' => $profile->getId(),
                    'schoolId'  => $school->getId(),
                    'seasonId'  => $season->getId(),
                ]);
            }
        }

        return $this->render($template, [
            'school'              => $school,
            'season'              => $season,
            'events'              => $events,
            'packages'            => $packages,
            'schoolProfile'       => $schoolProfileSeason,
            'schoolProfileSeason' => $schoolProfileSeason,
        ]);
    }

    private function resolveSeason(School $school, ?string $seasonId): ?Season
    {
        if ($seasonId !== null) {
            return $this->em->getRepository(Season::class)->find($seasonId);
        }

        $currentSeasonId = $school->getCurrentSeasonId();

        if ($currentSeasonId !== null) {
            $season = $this->em->getRepository(Season::class)->find($currentSeasonId);

            if ($season !== null) {
                $now = new \DateTimeImmutable();

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
