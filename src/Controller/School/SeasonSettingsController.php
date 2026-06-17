<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Address;
use App\Entity\AgeGroup;
use App\Entity\Event;
use App\Entity\EventOccurence;
use App\Entity\EventOccurenceProfile;
use App\Entity\Level;
use App\Entity\Package;
use App\Entity\PaymentScheduleTemplate;
use App\Entity\PriceModifier;
use App\Entity\Room;
use App\Entity\Season;
use App\Entity\TeamProfileGalaParticipation;
use App\Entity\User;
use App\Security\Voter\EventVoter;
use App\Security\Voter\SeasonVoter;
use App\Security\Voter\TeamVoter;
use App\Service\Event\EventService;
use App\Service\TeamContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/school/settings/season/{id}')]
#[IsGranted('ROLE_USER')]
final class SeasonSettingsController extends AbstractController
{
    public function __construct(
        private readonly TeamContextService $teamContext,
        private readonly EntityManagerInterface $em,
        private readonly EventService $eventService,
    ) {
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function loadSeasonForAdmin(string $id): array
    {
        /** @var User $user */
        $user   = $this->getUser();
        $team   = $this->teamContext->getCurrentTeam();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        if ($season === null || $season->getTeamId() !== $team->getId()) {
            throw $this->createNotFoundException('Season not found.');
        }

        $this->denyAccessUnlessGranted(SeasonVoter::UPDATE, $season);

        return [$team, $season];
    }

    // -------------------------------------------------------------------------
    // Lessons
    // -------------------------------------------------------------------------

    #[Route('/lessons', name: 'school_season_lessons', methods: ['GET'])]
    public function lessons(string $id): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $lessons = $this->em->getRepository(Event::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/lessons/list.html.twig', [
            'team'    => $team,
            'season'  => $season,
            'lessons' => $lessons,
        ]);
    }

    #[Route('/lessons/create', name: 'school_season_lessons_create', methods: ['POST'])]
    public function lessonCreate(string $id, Request $request): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $event = $this->eventService->createEvent($team, $season, $request->request->all());

        $this->denyAccessUnlessGranted(EventVoter::CREATE, $event);
        $this->addFlash('success', 'Lesson created.');

        return $this->redirectToRoute('school_season_lessons', ['id' => $id]);
    }

    #[Route('/lessons/{lessonId}', name: 'school_season_lesson_detail', methods: ['GET'])]
    public function lessonDetail(string $id, string $lessonId): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $lesson = $this->em->getRepository(Event::class)->find($lessonId);

        if ($lesson === null || $lesson->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Lesson not found.');
        }

        return $this->render('school/settings/season/lessons/detail.html.twig', [
            'team'   => $team,
            'season' => $season,
            'lesson' => $lesson,
        ]);
    }

    #[Route('/lessons/{lessonId}/edit', name: 'school_season_lesson_edit', methods: ['GET', 'POST'])]
    public function lessonEdit(string $id, string $lessonId, Request $request): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $lesson = $this->em->getRepository(Event::class)->find($lessonId);

        if ($lesson === null || $lesson->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Lesson not found.');
        }

        if ($request->isMethod('POST')) {
            $this->eventService->updateEvent($lesson, $request->request->all());
            $this->addFlash('success', 'Lesson updated.');

            return $this->redirectToRoute('school_season_lesson_detail', ['id' => $id, 'lessonId' => $lessonId]);
        }

        return $this->render('school/settings/season/lessons/edit.html.twig', [
            'team'   => $team,
            'season' => $season,
            'lesson' => $lesson,
        ]);
    }

    #[Route('/lessons/{lessonId}/occurences', name: 'school_season_lesson_occurences', methods: ['GET'])]
    public function lessonOccurences(string $id, string $lessonId): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $lesson = $this->em->getRepository(Event::class)->find($lessonId);

        if ($lesson === null || $lesson->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Lesson not found.');
        }

        $occurences = $this->em->getRepository(EventOccurence::class)->findBy(
            ['eventId' => $lesson->getId()],
            ['occurenceAt' => 'ASC'],
        );

        return $this->render('school/settings/season/lessons/occurences.html.twig', [
            'team'      => $team,
            'season'    => $season,
            'lesson'    => $lesson,
            'occurences' => $occurences,
        ]);
    }

    #[Route('/lessons/{lessonId}/participants', name: 'school_season_lesson_participants', methods: ['GET'])]
    public function lessonParticipants(string $id, string $lessonId): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $lesson = $this->em->getRepository(Event::class)->find($lessonId);

        if ($lesson === null || $lesson->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Lesson not found.');
        }

        $participants = $this->em->createQueryBuilder()
            ->select('eop')
            ->from(EventOccurenceProfile::class, 'eop')
            ->join(EventOccurence::class, 'eo', 'WITH', 'eo.id = eop.occurenceId')
            ->where('eo.eventId = :eventId')
            ->setParameter('eventId', $lesson->getId())
            ->getQuery()
            ->getResult();

        return $this->render('school/settings/season/lessons/participants.html.twig', [
            'team'         => $team,
            'season'       => $season,
            'lesson'       => $lesson,
            'participants' => $participants,
        ]);
    }

    // -------------------------------------------------------------------------
    // Packages
    // -------------------------------------------------------------------------

    #[Route('/packages', name: 'school_season_packages', methods: ['GET'])]
    public function packages(string $id): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $packages = $this->em->getRepository(Package::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/packages.html.twig', [
            'team'     => $team,
            'season'   => $season,
            'packages' => $packages,
        ]);
    }

    // -------------------------------------------------------------------------
    // Payment schedulers
    // -------------------------------------------------------------------------

    #[Route('/payment-schedulers', name: 'school_season_payment_schedulers', methods: ['GET'])]
    public function paymentSchedulers(string $id): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $templates = $this->em->getRepository(PaymentScheduleTemplate::class)->findBy([
            'seasonId' => $season->getId(),
        ]);

        return $this->render('school/settings/season/payment_schedulers.html.twig', [
            'team'      => $team,
            'season'    => $season,
            'templates' => $templates,
        ]);
    }

    // -------------------------------------------------------------------------
    // Price modifiers
    // -------------------------------------------------------------------------

    #[Route('/price-modifiers', name: 'school_season_price_modifiers', methods: ['GET'])]
    public function priceModifiers(string $id): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $modifiers = $this->em->getRepository(PriceModifier::class)->findBy([
            'seasonId' => $season->getId(),
        ]);

        return $this->render('school/settings/season/price_modifiers.html.twig', [
            'team'      => $team,
            'season'    => $season,
            'modifiers' => $modifiers,
        ]);
    }

    // -------------------------------------------------------------------------
    // Rooms
    // -------------------------------------------------------------------------

    #[Route('/rooms', name: 'school_season_rooms', methods: ['GET', 'POST'])]
    public function rooms(string $id, Request $request): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $room = new Room();
            $room->setTeamId($team->getId());
            $room->setSeasonId($season->getId());
            $room->setName((string) $request->request->get('name'));
            $this->em->persist($room);
            $this->em->flush();
            $this->addFlash('success', 'Room created.');

            return $this->redirectToRoute('school_season_rooms', ['id' => $id]);
        }

        $rooms = $this->em->getRepository(Room::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/rooms.html.twig', [
            'team'   => $team,
            'season' => $season,
            'rooms'  => $rooms,
        ]);
    }

    // -------------------------------------------------------------------------
    // Levels
    // -------------------------------------------------------------------------

    #[Route('/levels', name: 'school_season_levels', methods: ['GET', 'POST'])]
    public function levels(string $id, Request $request): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $level = new Level();
            $level->setTeamId($team->getId());
            $level->setSeasonId($season->getId());
            $level->setName((string) $request->request->get('name'));
            $this->em->persist($level);
            $this->em->flush();
            $this->addFlash('success', 'Level created.');

            return $this->redirectToRoute('school_season_levels', ['id' => $id]);
        }

        $levels = $this->em->getRepository(Level::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/levels.html.twig', [
            'team'   => $team,
            'season' => $season,
            'levels' => $levels,
        ]);
    }

    // -------------------------------------------------------------------------
    // Age groups
    // -------------------------------------------------------------------------

    #[Route('/age-groups', name: 'school_season_age_groups', methods: ['GET', 'POST'])]
    public function ageGroups(string $id, Request $request): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $group = new AgeGroup();
            $group->setTeamId($team->getId());
            $group->setSeasonId($season->getId());
            $group->setName((string) $request->request->get('name'));
            $group->setMinAge($request->request->get('minAge') !== null ? (int) $request->request->get('minAge') : null);
            $group->setMaxAge($request->request->get('maxAge') !== null ? (int) $request->request->get('maxAge') : null);
            $this->em->persist($group);
            $this->em->flush();
            $this->addFlash('success', 'Age group created.');

            return $this->redirectToRoute('school_season_age_groups', ['id' => $id]);
        }

        $groups = $this->em->getRepository(AgeGroup::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/age_groups.html.twig', [
            'team'   => $team,
            'season' => $season,
            'groups' => $groups,
        ]);
    }

    // -------------------------------------------------------------------------
    // Addresses
    // -------------------------------------------------------------------------

    #[Route('/addresses', name: 'school_season_addresses', methods: ['GET', 'POST'])]
    public function addresses(string $id, Request $request): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $address = new Address();
            $address->setTeamId($team->getId());
            $address->setSeasonId($season->getId());
            $address->setName((string) $request->request->get('name'));
            $address->setAddress((string) $request->request->get('address'));
            $this->em->persist($address);
            $this->em->flush();
            $this->addFlash('success', 'Address created.');

            return $this->redirectToRoute('school_season_addresses', ['id' => $id]);
        }

        $addresses = $this->em->getRepository(Address::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/addresses.html.twig', [
            'team'      => $team,
            'season'    => $season,
            'addresses' => $addresses,
        ]);
    }

    // -------------------------------------------------------------------------
    // Gala
    // -------------------------------------------------------------------------

    #[Route('/gala', name: 'school_season_gala', methods: ['GET'])]
    public function gala(string $id): Response
    {
        [$team, $season] = $this->loadSeasonForAdmin($id);

        $participations = $this->em->getRepository(TeamProfileGalaParticipation::class)->findBy([
            'seasonId' => $season->getId(),
            'teamId'   => $team->getId(),
        ]);

        return $this->render('school/settings/season/gala.html.twig', [
            'team'           => $team,
            'season'         => $season,
            'participations' => $participations,
        ]);
    }
}
