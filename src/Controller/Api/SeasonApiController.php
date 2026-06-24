<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Activity;
use App\Entity\Event;
use App\Entity\Package;
use App\Entity\Room;
use App\Entity\Season;
use App\Entity\School;
use App\Entity\User;
use App\Service\Season\SeasonService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SeasonApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SeasonService $seasonService,
    ) {
    }

    /**
     * POST /api/v1/seasons/{id}/copy
     * Copy a season into a new one.
     */
    #[Route('/api/v1/seasons/{id}/copy', name: 'api_v1_seasons_copy', methods: ['POST'])]
    public function copy(string $id, Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $season = $this->em->getRepository(Season::class)->find($id);

        if ($season === null) {
            return new JsonResponse(['success' => false, 'error' => 'Season not found.'], 404);
        }

        $school = $this->em->getRepository(School::class)->find($season->getSchool()->getId());

        if ($school === null) {
            return new JsonResponse(['success' => false, 'error' => 'School not found.'], 404);
        }

        $data    = json_decode($request->getContent(), true) ?? [];
        $newName = $data['name'] ?? throw new \InvalidArgumentException('name is required.');

        try {
            $start = new \DateTimeImmutable($data['startAt'] ?? 'now');
            $end   = new \DateTimeImmutable($data['endAt'] ?? 'now');
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'error' => 'Invalid date format.'], 422);
        }

        try {
            $newSeason = $this->seasonService->copySeason($season, $school, $newName, $start, $end);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success'  => true,
            'seasonId' => $newSeason->getId(),
        ], 201);
    }

    /**
     * GET /api/v1/seasons/{id}/entities
     * List all sub-entities of a season: rooms, packages, events, activities, age groups, levels.
     */
    #[Route('/api/v1/seasons/{id}/entities', name: 'api_v1_seasons_entities', methods: ['GET'])]
    public function entities(string $id): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $season = $this->em->getRepository(Season::class)->find($id);

        if ($season === null) {
            return new JsonResponse(['success' => false, 'error' => 'Season not found.'], 404);
        }

        $seasonId = $season->getId();

        $rooms = array_map(
            static fn (Room $r) => ['id' => $r->getId(), 'name' => $r->getName()],
            $this->em->getRepository(Room::class)->findBy(['seasonId' => $seasonId]),
        );

        $packages = array_map(
            static fn (Package $p) => ['id' => $p->getId(), 'name' => $p->getName(), 'type' => $p->getType()->value],
            $this->em->getRepository(Package::class)->findBy(['seasonId' => $seasonId]),
        );

        $events = array_map(
            static fn (Event $e) => ['id' => $e->getId(), 'name' => $e->getName(), 'type' => $e->getType()->value],
            $this->em->getRepository(Event::class)->findBy(['seasonId' => $seasonId]),
        );

        $activities = array_map(
            static fn (Activity $a) => ['id' => $a->getId(), 'name' => $a->getName()],
            $this->em->getRepository(Activity::class)->findBy(['seasonId' => $seasonId]),
        );

        return new JsonResponse([
            'success'    => true,
            'rooms'      => $rooms,
            'packages'   => $packages,
            'events'     => $events,
            'activities' => $activities,
        ]);
    }
}
