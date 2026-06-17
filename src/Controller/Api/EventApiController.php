<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Event;
use App\Entity\Season;
use App\Service\Event\EventService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class EventApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EventService $eventService,
        private readonly string $cronSecret,
    ) {
    }

    /**
     * POST /api/v1/events/adjust-occurences
     * Regenerate occurrences for all events. Protected by CRON_SECRET header.
     */
    #[Route('/api/v1/events/adjust-occurences', name: 'api_v1_events_adjust_occurences', methods: ['POST'])]
    public function adjustOccurences(Request $request): JsonResponse
    {
        $cronSecretHeader = $request->headers->get('X-Cron-Secret') ?? $request->headers->get('CRON_SECRET');

        if ($cronSecretHeader !== $this->cronSecret) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthorized.'], 401);
        }

        /** @var Event[] $events */
        $events = $this->em->getRepository(Event::class)->findAll();

        $processed = 0;
        $errors    = [];

        foreach ($events as $event) {
            $season = $this->em->getRepository(Season::class)->find($event->getSeasonId());

            if ($season === null) {
                $errors[] = sprintf('Season not found for event "%s".', $event->getId());
                continue;
            }

            try {
                $this->eventService->generateOccurrences($event, $season);
                ++$processed;
            } catch (\Throwable $e) {
                $errors[] = sprintf('Event "%s": %s', $event->getId(), $e->getMessage());
            }
        }

        return new JsonResponse([
            'success'   => true,
            'processed' => $processed,
            'errors'    => $errors,
        ]);
    }
}
