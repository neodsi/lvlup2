<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Address;
use App\Entity\Event;
use App\Entity\EventOccurence;
use App\Entity\EventOccurenceProfile;
use App\Entity\Package;
use App\Entity\PaymentScheduleTemplate;
use App\Entity\PriceModifier;
use App\Entity\Room;
use App\Entity\Season;
use App\Entity\SchoolProfileGalaParticipation;
use App\Entity\User;
use App\Enum\Operation;
use App\Enum\PriceModifierType;
use App\Enum\ScheduleType;
use App\Enum\ValueType;
use App\Security\Voter\EventVoter;
use App\Security\Voter\SeasonVoter;
use App\Security\Voter\SchoolVoter;
use App\Service\Event\EventService;
use App\Service\SchoolContextService;
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
        private readonly SchoolContextService $schoolContext,
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
        $school   = $this->schoolContext->getCurrentSchool();
        $season = $this->em->getRepository(Season::class)->find($id);

        if ($school === null || $this->schoolContext->getCurrentSchoolUser($user) === null) {
            return $this->redirectToRoute('app_create_school');
        }

        if ($season === null || $season->getSchoolId() !== $school->getId()) {
            throw $this->createNotFoundException('Season not found.');
        }

        $this->denyAccessUnlessGranted(SeasonVoter::UPDATE, $season);

        return [$school, $season];
    }

    // -------------------------------------------------------------------------
    // Events (cours)
    // -------------------------------------------------------------------------

    #[Route('/events', name: 'school_season_events', methods: ['GET'])]
    public function events(string $id): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $events = $this->em->getRepository(Event::class)->findBy(['seasonId' => $season->getId()]);
        $rooms  = $this->em->getRepository(Room::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );

        return $this->render('school/settings/season/lessons/list.html.twig', [
            'school'  => $school,
            'season'  => $season,
            'lessons' => $events,
            'rooms'   => $rooms,
        ]);
    }

    #[Route('/events/create', name: 'school_season_event_create', methods: ['GET', 'POST'])]
    public function eventCreate(string $id, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $p = $request->request;
            $mode      = $p->get('mode', 'unique');
            $startTime = $p->get('startTime', '09:00');
            $endTime   = $p->get('endTime', '10:00');

            if ($mode === 'weekly') {
                $startDate  = $p->get('recurStartDate');
                $recurUntil = $p->get('recurUntil');
                $days       = $p->all('days') ?: ['MO'];
                $until      = (new \DateTimeImmutable($recurUntil))->format('Ymd') . 'T235959Z';
                $rrule      = 'FREQ=WEEKLY;BYDAY=' . implode(',', $days) . ';UNTIL=' . $until;
            } else {
                $startDate = $p->get('eventDate');
                $rrule     = 'FREQ=DAILY;COUNT=1';
            }

            $startAt = new \DateTimeImmutable($startDate . 'T' . $startTime . ':00');
            $endAt   = new \DateTimeImmutable($startDate . 'T' . $endTime . ':00');

            $minAge          = $p->get('minAge');
            $maxAge          = $p->get('maxAge');
            $maxParticipants = $p->get('maxParticipants');

            $data = [
                'name'            => $p->get('name'),
                'type'            => 'lesson',
                'rrule'           => $rrule,
                'startAt'         => $startAt,
                'endAt'           => $endAt,
                'visible'         => $p->has('visible'),
                'minAge'          => ($minAge !== null && $minAge !== '') ? (int) $minAge : null,
                'maxAge'          => ($maxAge !== null && $maxAge !== '') ? (int) $maxAge : null,
                'maxParticipants' => ($maxParticipants !== null && $maxParticipants !== '') ? (int) $maxParticipants : null,
                'description'     => $p->get('description'),
            ];

            if ($p->get('roomId')) {
                $data['roomId'] = $p->get('roomId');
            }

            $event = $this->eventService->createEvent($school, $season, $data);
            $this->denyAccessUnlessGranted(EventVoter::CREATE, $event);

            $levelIds = array_values(array_filter((array) $p->all('levelIds')));
            $levelRepo = $this->em->getRepository(\App\Entity\Level::class);
            $levels = array_map(fn($lid) => $levelRepo->find($lid), $levelIds);
            $event->syncLevels(array_filter($levels));
            $this->em->flush();

            $this->addFlash('success', 'Cours créé.');

            return $this->redirectToRoute('school_season_events', ['id' => $id]);
        }

        $rooms  = $this->em->getRepository(Room::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );
        $levels = $this->em->getRepository(\App\Entity\Level::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );

        return $this->render('school/settings/season/lessons/create.html.twig', [
            'school'  => $school,
            'season'  => $season,
            'rooms'   => $rooms,
            'levels'  => $levels,
        ]);
    }

    #[Route('/events/{eventId}/edit', name: 'school_season_event_edit', methods: ['GET', 'POST'])]
    public function eventEdit(string $id, string $eventId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $event = $this->em->getRepository(Event::class)->find($eventId);

        if ($event === null || $event->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Event not found.');
        }

        if ($request->isMethod('POST')) {
            $p = $request->request;

            $mode      = $p->get('mode', 'unique');
            $startTime = $p->get('startTime', '09:00');
            $endTime   = $p->get('endTime', '10:00');

            if ($mode === 'weekly') {
                $startDate  = $p->get('recurStartDate');
                $recurUntil = $p->get('recurUntil');
                $days       = $p->all('days') ?: ['MO'];
                $until      = (new \DateTimeImmutable($recurUntil))->format('Ymd') . 'T235959Z';
                $rrule      = 'FREQ=WEEKLY;BYDAY=' . implode(',', $days) . ';UNTIL=' . $until;
            } else {
                $startDate = $p->get('eventDate');
                $rrule     = 'FREQ=DAILY;COUNT=1';
            }

            $maxParticipants = $p->get('maxParticipants');
            $minAge          = $p->get('minAge');
            $maxAge          = $p->get('maxAge');

            $data = [
                'name'            => $p->get('name'),
                'rrule'           => $rrule,
                'startAt'         => new \DateTimeImmutable($startDate . 'T' . $startTime . ':00'),
                'endAt'           => new \DateTimeImmutable($startDate . 'T' . $endTime . ':00'),
                'roomId'          => $p->get('roomId') ?: null,
                'visible'         => $p->has('visible'),
                'maxParticipants' => ($maxParticipants !== null && $maxParticipants !== '') ? (int) $maxParticipants : null,
                'minAge'          => ($minAge !== null && $minAge !== '') ? (int) $minAge : null,
                'maxAge'          => ($maxAge !== null && $maxAge !== '') ? (int) $maxAge : null,
                'description'     => $p->get('description'),
            ];

            $this->eventService->updateEvent($event, $data);

            $levelIds = array_values(array_filter((array) $p->all('levelIds')));
            $levelRepo = $this->em->getRepository(\App\Entity\Level::class);
            $levels = array_map(fn($lid) => $levelRepo->find($lid), $levelIds);
            $event->syncLevels(array_filter($levels));
            $this->em->flush();

            $this->addFlash('success', 'Cours mis à jour.');

            return $this->redirectToRoute('school_season_event_edit', ['id' => $id, 'eventId' => $eventId]);
        }

        $rrule       = $event->getRrule();
        $parsedMode  = str_contains($rrule, 'FREQ=WEEKLY') ? 'weekly' : 'unique';
        $parsedDays  = [];
        $parsedUntil = '';

        if ($parsedMode === 'weekly') {
            if (preg_match('/BYDAY=([^;]+)/', $rrule, $m)) {
                $parsedDays = explode(',', $m[1]);
            }
            if (preg_match('/UNTIL=(\d{8})/', $rrule, $m)) {
                $parsedUntil = substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
            }
        }

        $rooms = $this->em->getRepository(Room::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );
        $levels = $this->em->getRepository(\App\Entity\Level::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );

        return $this->render('school/settings/season/lessons/edit.html.twig', [
            'school'      => $school,
            'season'      => $season,
            'lesson'      => $event,
            'rooms'       => $rooms,
            'levels'      => $levels,
            'parsedMode'  => $parsedMode,
            'parsedDays'  => $parsedDays,
            'parsedUntil' => $parsedUntil,
        ]);
    }

    #[Route('/events/{eventId}/delete', name: 'school_season_event_delete', methods: ['POST'])]
    public function eventDelete(string $id, string $eventId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $event = $this->em->getRepository(Event::class)->find($eventId);

        if ($event === null || $event->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Event not found.');
        }

        if (!$this->isCsrfTokenValid('event_delete_' . $eventId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('school_season_event_edit', ['id' => $id, 'eventId' => $eventId]);
        }

        $hasEnrollments = $this->em->createQueryBuilder()
            ->select('COUNT(eop.id)')
            ->from(EventOccurenceProfile::class, 'eop')
            ->join(EventOccurence::class, 'eo', 'WITH', 'eo.id = eop.eventOccurenceId')
            ->where('eo.eventId = :eventId')
            ->setParameter('eventId', $eventId)
            ->getQuery()
            ->getSingleScalarResult() > 0;

        if ($hasEnrollments) {
            $this->addFlash('error', 'Impossible de supprimer ce cours : des élèves y sont inscrits.');
            return $this->redirectToRoute('school_season_event_edit', ['id' => $id, 'eventId' => $eventId]);
        }

        $this->em->wrapInTransaction(function () use ($event, $eventId): void {
            $occurences = $this->em->getRepository(EventOccurence::class)->findBy(['eventId' => $eventId]);
            foreach ($occurences as $occurence) {
                $this->em->remove($occurence);
            }
            $this->em->remove($event);
        });

        $this->addFlash('success', 'Cours supprimé.');
        return $this->redirectToRoute('school_season_events', ['id' => $id]);
    }

    #[Route('/events/{eventId}/occurences', name: 'school_season_event_occurences', methods: ['GET'])]
    public function eventOccurences(string $id, string $eventId): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $event = $this->em->getRepository(Event::class)->find($eventId);

        if ($event === null || $event->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Event not found.');
        }

        $occurences = $this->em->getRepository(EventOccurence::class)->findBy(
            ['eventId' => $event->getId()],
            ['occurenceAt' => 'ASC'],
        );

        return $this->render('school/settings/season/lessons/occurences.html.twig', [
            'school'     => $school,
            'season'     => $season,
            'lesson'     => $event,
            'occurences' => $occurences,
        ]);
    }

    #[Route('/events/{eventId}/participants', name: 'school_season_event_participants', methods: ['GET'])]
    public function eventParticipants(string $id, string $eventId): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $event = $this->em->getRepository(Event::class)->find($eventId);

        if ($event === null || $event->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Event not found.');
        }

        $participants = $this->em->createQueryBuilder()
            ->select('eop')
            ->from(EventOccurenceProfile::class, 'eop')
            ->join(EventOccurence::class, 'eo', 'WITH', 'eo.id = eop.eventOccurenceId')
            ->where('eo.eventId = :eventId')
            ->setParameter('eventId', $event->getId())
            ->getQuery()
            ->getResult();

        return $this->render('school/settings/season/lessons/participants.html.twig', [
            'school'       => $school,
            'season'       => $season,
            'lesson'       => $event,
            'participants' => $participants,
        ]);
    }

    // -------------------------------------------------------------------------
    // Packages
    // -------------------------------------------------------------------------

    #[Route('/packages', name: 'school_season_packages', methods: ['GET'])]
    public function packages(string $id): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $packages = $this->em->getRepository(Package::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/packages.html.twig', [
            'school'   => $school,
            'season'   => $season,
            'packages' => $packages,
        ]);
    }

    #[Route('/packages/create', name: 'school_season_package_create', methods: ['GET', 'POST'])]
    public function packageCreate(string $id, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $lockedType = $request->request->get('lockedType');
            $type = \App\Enum\PackageType::from((string) $request->request->get('type'));

            $package = new Package();
            $package->setSchoolId($school->getId());
            $package->setSeasonId($season->getId());
            $package->setName((string) $request->request->get('name'));
            $package->setType($type);
            $package->setPrice((int) round((float) $request->request->get('price', 0) * 100));
            $cap = $request->request->get('classesQty');
            $package->setClassesQty($cap !== null && $cap !== '' ? (int) $cap : null);
            $package->setDescription($request->request->get('description') ?: null);

            if ($type === \App\Enum\PackageType::ALaCarte) {
                $vdd = $request->request->get('validityDurationDays');
                $package->setValidityDurationDays($vdd !== null && $vdd !== '' ? (int) $vdd : null);
                $cdm = $request->request->get('cancellationDelayMinutes');
                $package->setCancellationDelayMinutes($cdm !== null && $cdm !== '' ? (int) $cdm : null);
                $package->setValidityStartType(\App\Enum\ValidityStartType::AtAttribution);
                $package->setExpirationType(\App\Enum\ExpirationType::Seasonal);
            } else {
                $package->setValidityStartType(\App\Enum\ValidityStartType::from(
                    $request->request->get('validityStartType', 'at_attribution')
                ));
                $vsa = $request->request->get('validityStartsAt');
                $package->setValidityStartsAt($vsa ? new \DateTimeImmutable($vsa) : null);
                $package->setExpirationType(\App\Enum\ExpirationType::from(
                    $request->request->get('expirationType', 'seasonal')
                ));
                $ea = $request->request->get('expiresAt');
                $package->setExpiresAt($ea ? new \DateTimeImmutable($ea) : null);
            }

            $package->setPreRegistrationPaymentType($request->request->get('preRegistrationPaymentType') ?: null);
            $this->em->persist($package);

            $selectedEventIds = $request->request->all('eventIds') ?: [];
            foreach ($selectedEventIds as $eventId) {
                $event = $this->em->getRepository(Event::class)->find($eventId);
                if ($event && $event->getSeasonId() === $season->getId()) {
                    $event->addPackage($package);
                    $this->em->persist($event);
                }
            }

            if ($lockedType === 'registration_fee') {
                $season->setRegistrationFeeId($package->getId());
                $this->em->flush();
                $this->addFlash('success', 'Forfait frais d\'inscription créé.');
                return $this->redirectToRoute('school_settings_season', ['id' => $id]);
            }

            $this->em->flush();
            $this->addFlash('success', 'Forfait créé.');

            return $this->redirectToRoute('school_season_packages', ['id' => $id]);
        }

        $lockedType = $request->query->get('type');
        $events = $this->em->getRepository(Event::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );

        return $this->render('school/settings/season/package_form.html.twig', [
            'school'              => $school,
            'season'              => $season,
            'package'             => null,
            'lockedType'          => $lockedType,
            'events'              => $events,
            'associatedEventIds'  => [],
        ]);
    }

    #[Route('/packages/{packageId}/edit', name: 'school_season_package_edit', methods: ['GET', 'POST'])]
    public function packageEdit(string $id, string $packageId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $package = $this->em->getRepository(Package::class)->find($packageId);

        if ($package === null || $package->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Forfait introuvable.');
        }

        if ($request->isMethod('POST')) {
            $type = \App\Enum\PackageType::from((string) $request->request->get('type'));
            $package->setName((string) $request->request->get('name'));
            $package->setType($type);
            $package->setPrice((int) round((float) $request->request->get('price', 0) * 100));
            $cap = $request->request->get('classesQty');
            $package->setClassesQty($cap !== null && $cap !== '' ? (int) $cap : null);
            $package->setDescription($request->request->get('description') ?: null);

            if ($type === \App\Enum\PackageType::ALaCarte) {
                $vdd = $request->request->get('validityDurationDays');
                $package->setValidityDurationDays($vdd !== null && $vdd !== '' ? (int) $vdd : null);
                $cdm = $request->request->get('cancellationDelayMinutes');
                $package->setCancellationDelayMinutes($cdm !== null && $cdm !== '' ? (int) $cdm : null);
                $package->setValidityStartType(\App\Enum\ValidityStartType::AtAttribution);
                $package->setExpirationType(\App\Enum\ExpirationType::Seasonal);
            } else {
                $package->setValidityStartType(\App\Enum\ValidityStartType::from(
                    $request->request->get('validityStartType', 'at_attribution')
                ));
                $vsa = $request->request->get('validityStartsAt');
                $package->setValidityStartsAt($vsa ? new \DateTimeImmutable($vsa) : null);
                $package->setExpirationType(\App\Enum\ExpirationType::from(
                    $request->request->get('expirationType', 'seasonal')
                ));
                $ea = $request->request->get('expiresAt');
                $package->setExpiresAt($ea ? new \DateTimeImmutable($ea) : null);
            }

            $package->setPreRegistrationPaymentType($request->request->get('preRegistrationPaymentType') ?: null);

            $selectedEventIds = $request->request->all('eventIds') ?: [];
            $events = $this->em->getRepository(Event::class)->findBy(
                ['seasonId' => $season->getId(), 'deletedAt' => null]
            );
            foreach ($events as $event) {
                if (in_array($event->getId(), $selectedEventIds, true)) {
                    $event->addPackage($package);
                } else {
                    $event->removePackage($package);
                }
                $this->em->persist($event);
            }

            $this->em->flush();
            $this->addFlash('success', 'Forfait mis à jour.');

            return $this->redirectToRoute('school_season_packages', ['id' => $id]);
        }

        $events = $this->em->getRepository(Event::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );
        $associatedEventIds = $package->getId()
            ? array_map(
                fn($e) => $e->getId(),
                array_filter(
                    $this->em->getRepository(Event::class)->findBy(['seasonId' => $season->getId(), 'deletedAt' => null]),
                    fn($e) => $e->getPackages()->exists(fn($k, $p) => $p->getId() === $package->getId())
                )
            )
            : [];

        return $this->render('school/settings/season/package_form.html.twig', [
            'school'             => $school,
            'season'             => $season,
            'package'            => $package,
            'lockedType'         => null,
            'events'             => $events,
            'associatedEventIds' => array_values($associatedEventIds),
        ]);
    }

    #[Route('/packages/{packageId}/delete', name: 'school_season_package_delete', methods: ['POST'])]
    public function packageDelete(string $id, string $packageId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $package = $this->em->getRepository(Package::class)->find($packageId);

        if ($package === null || $package->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Forfait introuvable.');
        }

        if (!$this->isCsrfTokenValid('package_delete_' . $packageId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('school_season_packages', ['id' => $id]);
        }

        $orderItemCount = $this->em->createQuery(
            'SELECT COUNT(o.id) FROM App\Entity\OrderItem o WHERE o.packageId = :pid'
        )->setParameter('pid', $packageId)->getSingleScalarResult();

        if ($package->getUsageCount() > 0 || $orderItemCount > 0) {
            $package->setDeletedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Forfait archivé (il est référencé dans des paiements ou attributions).');
        } else {
            $this->em->remove($package);
            $this->em->flush();
            $this->addFlash('success', 'Forfait supprimé.');
        }

        return $this->redirectToRoute('school_season_packages', ['id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Payment schedulers
    // -------------------------------------------------------------------------

    #[Route('/payment-schedulers', name: 'school_season_payment_schedulers', methods: ['GET', 'POST'])]
    public function paymentSchedulers(string $id, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $tpl = new PaymentScheduleTemplate();
            $tpl->setSchoolId($school->getId());
            $tpl->setSeasonId($season->getId());
            $this->applyPaymentSchedulerFromRequest($tpl, $request, $school->getId(), $season->getId());
            $this->em->persist($tpl);
            $this->em->flush();
            $this->addFlash('success', 'Échéancier créé.');

            return $this->redirectToRoute('school_season_payment_schedulers', ['id' => $id]);
        }

        $templates = $this->em->getRepository(PaymentScheduleTemplate::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );

        $feeModifiers = [];
        foreach ($templates as $tpl) {
            if ($tpl->getPriceModifierId() !== null) {
                $fm = $this->em->getRepository(PriceModifier::class)->find($tpl->getPriceModifierId());
                if ($fm !== null) {
                    $feeModifiers[$tpl->getId()] = $fm;
                }
            }
        }

        return $this->render('school/settings/season/payment_schedulers.html.twig', [
            'school'       => $school,
            'season'       => $season,
            'templates'    => $templates,
            'feeModifiers' => $feeModifiers,
        ]);
    }

    #[Route('/payment-schedulers/{templateId}/edit', name: 'school_season_payment_scheduler_edit', methods: ['POST'])]
    public function paymentSchedulerEdit(string $id, string $templateId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $tpl = $this->em->getRepository(PaymentScheduleTemplate::class)->find($templateId);
        if ($tpl === null || $tpl->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException();
        }

        $this->applyPaymentSchedulerFromRequest($tpl, $request, $school->getId(), $season->getId());
        $this->em->flush();
        $this->addFlash('success', 'Échéancier mis à jour.');

        return $this->redirectToRoute('school_season_payment_schedulers', ['id' => $id]);
    }

    #[Route('/payment-schedulers/{templateId}/delete', name: 'school_season_payment_scheduler_delete', methods: ['POST'])]
    public function paymentSchedulerDelete(string $id, string $templateId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $tpl = $this->em->getRepository(PaymentScheduleTemplate::class)->find($templateId);
        if ($tpl === null || $tpl->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('scheduler_delete_' . $templateId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('school_season_payment_schedulers', ['id' => $id]);
        }

        $tpl->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Échéancier supprimé.');

        return $this->redirectToRoute('school_season_payment_schedulers', ['id' => $id]);
    }

    private function applyPaymentSchedulerFromRequest(PaymentScheduleTemplate $tpl, Request $request, string $schoolId, string $seasonId): void
    {
        $tpl->setName((string) $request->request->get('name'));
        $tpl->setVisibility($request->request->get('visibility') === 'private' ? 'private' : 'public');

        $minAmountRaw = $request->request->get('minAmount');
        $tpl->setMinAmount($minAmountRaw !== null && $minAmountRaw !== '' ? (int) round((float) $minAmountRaw * 100) : null);

        $type = ScheduleType::from((string) $request->request->get('type'));
        $tpl->setType($type);

        if ($type === ScheduleType::Recurring) {
            $tpl->setNbPayments((int) $request->request->get('nbPayments', 2));
            $tpl->setIntervalDuration($request->request->get('intervalDuration') !== '' ? (int) $request->request->get('intervalDuration') : null);
            $tpl->setDayOfMonth($request->request->get('dayOfMonth') !== '' ? (int) $request->request->get('dayOfMonth') : null);
            $tpl->setStartsAt($request->request->get('startsAt') ? new \DateTimeImmutable((string) $request->request->get('startsAt')) : null);
            $tpl->setFixedDates(null);
            $tpl->setFixedFirstDateIsAtAttribution(false);
        } else {
            $dates = $request->request->all('fixedDates');
            $dates = array_values(array_filter(array_map('trim', $dates)));
            sort($dates);
            $tpl->setFixedDates($dates ?: null);
            $tpl->setNbPayments(count($dates));
            $tpl->setFixedFirstDateIsAtAttribution((bool) $request->request->get('fixedFirstDateIsAtAttribution', false));
            $tpl->setIntervalDuration(null);
            $tpl->setDayOfMonth(null);
            $tpl->setStartsAt(null);
        }

        // --- Usage fee (frais d'utilisation) ---
        $feeEnabled = (bool) $request->request->get('feeEnabled', false);

        if ($feeEnabled) {
            $feeValueType = ValueType::from($request->request->get('feeValueType') === 'fixed' ? 'fixed' : 'percentage');
            $feeRaw       = (float) str_replace(',', '.', (string) $request->request->get('feeValue', '0'));
            $feeValue     = (int) round($feeRaw * 100);

            if ($tpl->getPriceModifierId() !== null) {
                $feeModifier = $this->em->getRepository(PriceModifier::class)->find($tpl->getPriceModifierId());
            } else {
                $feeModifier = null;
            }

            if ($feeModifier === null) {
                $feeModifier = new PriceModifier();
                $feeModifier->setSchoolId($schoolId);
                $feeModifier->setSeasonId($seasonId);
                $feeModifier->setType(PriceModifierType::Cart);
                $feeModifier->setOperation(Operation::Add);
                $feeModifier->setVisibility('private');
                $this->em->persist($feeModifier);
            }

            $feeModifier->setName('Frais d\'échéancier : ' . $tpl->getName());
            $feeModifier->setValueType($feeValueType);
            $feeModifier->setValue($feeValue);
            $tpl->setPriceModifierId($feeModifier->getId());
        } else {
            // Remove existing fee modifier if disabled
            if ($tpl->getPriceModifierId() !== null) {
                $feeModifier = $this->em->getRepository(PriceModifier::class)->find($tpl->getPriceModifierId());
                if ($feeModifier !== null) {
                    $feeModifier->setDeletedAt(new \DateTimeImmutable());
                }
            }
            $tpl->setPriceModifierId(null);
        }
    }

    // -------------------------------------------------------------------------
    // Price modifiers
    // -------------------------------------------------------------------------

    #[Route('/price-modifiers', name: 'school_season_price_modifiers', methods: ['GET'])]
    public function priceModifiers(string $id): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $modifiers = $this->em->getRepository(PriceModifier::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['createdAt' => 'DESC'],
        );

        return $this->render('school/settings/season/price_modifiers.html.twig', [
            'school'    => $school,
            'season'    => $season,
            'modifiers' => $modifiers,
        ]);
    }

    #[Route('/price-modifiers/create', name: 'school_season_price_modifier_create', methods: ['GET', 'POST'])]
    public function priceModifierCreate(string $id, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $modifier = new PriceModifier();
            $modifier->setSchoolId($school->getId());
            $modifier->setSeasonId($season->getId());
            $modifier->setName((string) $request->request->get('name'));
            $descRaw = $request->request->get('description');
            $modifier->setDescription($descRaw !== null && $descRaw !== '' ? (string) $descRaw : null);
            $visibility = $request->request->get('visibility') === 'private' ? 'private' : 'public';
            $modifier->setVisibility($visibility);
            $modifier->setType(PriceModifierType::from((string) $request->request->get('type')));
            $modifier->setOperation(Operation::from((string) $request->request->get('operation')));
            $modifier->setValueType(ValueType::from((string) $request->request->get('valueType')));

            $packageTypes = $request->request->all('package_types');
            $modifier->setTerms(!empty($packageTypes) ? ['package_types' => $packageTypes] : null);

            $valueType = ValueType::from((string) $request->request->get('valueType'));
            $rawValue  = (float) str_replace(',', '.', (string) $request->request->get('value', '0'));
            $modifier->setValue($valueType === ValueType::Percentage ? (int) round($rawValue * 100) : (int) round($rawValue * 100));

            $this->em->persist($modifier);
            $this->em->flush();
            $this->addFlash('success', 'Modificateur de prix créé.');

            return $this->redirectToRoute('school_season_price_modifiers', ['id' => $id]);
        }

        return $this->render('school/settings/season/price_modifier_form.html.twig', [
            'school'   => $school,
            'season'   => $season,
            'modifier' => null,
            'isEdit'   => false,
        ]);
    }

    #[Route('/price-modifiers/{modifierId}/edit', name: 'school_season_price_modifier_edit', methods: ['GET', 'POST'])]
    public function priceModifierEdit(string $id, string $modifierId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $modifier = $this->em->getRepository(PriceModifier::class)->find($modifierId);
        if ($modifier === null || $modifier->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $modifier->setName((string) $request->request->get('name'));
            $descRaw = $request->request->get('description');
            $modifier->setDescription($descRaw !== null && $descRaw !== '' ? (string) $descRaw : null);
            $visibility = $request->request->get('visibility') === 'private' ? 'private' : 'public';
            $modifier->setVisibility($visibility);
            $modifier->setType(PriceModifierType::from((string) $request->request->get('type')));
            $modifier->setOperation(Operation::from((string) $request->request->get('operation')));

            $packageTypes = $request->request->all('package_types');
            $modifier->setTerms(!empty($packageTypes) ? ['package_types' => $packageTypes] : null);

            $valueType = ValueType::from((string) $request->request->get('valueType'));
            $modifier->setValueType($valueType);
            $rawValue  = (float) str_replace(',', '.', (string) $request->request->get('value', '0'));
            $modifier->setValue($valueType === ValueType::Percentage ? (int) round($rawValue * 100) : (int) round($rawValue * 100));

            $this->em->flush();
            $this->addFlash('success', 'Modificateur de prix mis à jour.');

            return $this->redirectToRoute('school_season_price_modifiers', ['id' => $id]);
        }

        return $this->render('school/settings/season/price_modifier_form.html.twig', [
            'school'   => $school,
            'season'   => $season,
            'modifier' => $modifier,
            'isEdit'   => true,
        ]);
    }

    #[Route('/price-modifiers/{modifierId}/delete', name: 'school_season_price_modifier_delete', methods: ['POST'])]
    public function priceModifierDelete(string $id, string $modifierId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $modifier = $this->em->getRepository(PriceModifier::class)->find($modifierId);
        if ($modifier === null || $modifier->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('price_modifier_delete_' . $modifierId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('school_season_price_modifiers', ['id' => $id]);
        }

        $modifier->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Modificateur supprimé.');

        return $this->redirectToRoute('school_season_price_modifiers', ['id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Rooms
    // -------------------------------------------------------------------------

    #[Route('/rooms', name: 'school_season_rooms', methods: ['GET'])]
    public function rooms(string $id): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $rooms = $this->em->getRepository(Room::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/rooms.html.twig', [
            'school' => $school,
            'season' => $season,
            'rooms'  => $rooms,
        ]);
    }

    #[Route('/rooms/create', name: 'school_season_room_create', methods: ['GET', 'POST'])]
    public function roomCreate(string $id, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $room = new Room();
            $room->setSchoolId($school->getId());
            $room->setSeasonId($season->getId());
            $room->setName((string) $request->request->get('name'));
            $cap = $request->request->get('maxCapacity');
            $room->setMaxCapacity($cap !== null && $cap !== '' ? (int) $cap : null);
            $room->setAddressText($request->request->get('addressText') ?: null);
            $lat = $request->request->get('addressLat');
            $lng = $request->request->get('addressLng');
            $room->setAddressLat($lat !== null && $lat !== '' ? (float) $lat : null);
            $room->setAddressLng($lng !== null && $lng !== '' ? (float) $lng : null);
            $this->em->persist($room);
            $this->em->flush();
            $this->addFlash('success', 'Salle créée.');

            return $this->redirectToRoute('school_season_rooms', ['id' => $id]);
        }

        return $this->render('school/settings/season/room_form.html.twig', [
            'school' => $school,
            'season' => $season,
            'room'   => null,
        ]);
    }

    #[Route('/rooms/{roomId}/edit', name: 'school_season_room_edit', methods: ['GET', 'POST'])]
    public function roomEdit(string $id, string $roomId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $room = $this->em->getRepository(Room::class)->find($roomId);

        if ($room === null || $room->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Salle introuvable.');
        }

        if ($request->isMethod('POST')) {
            $room->setName((string) $request->request->get('name'));
            $cap = $request->request->get('maxCapacity');
            $room->setMaxCapacity($cap !== null && $cap !== '' ? (int) $cap : null);
            $room->setAddressText($request->request->get('addressText') ?: null);
            $lat = $request->request->get('addressLat');
            $lng = $request->request->get('addressLng');
            $room->setAddressLat($lat !== null && $lat !== '' ? (float) $lat : null);
            $room->setAddressLng($lng !== null && $lng !== '' ? (float) $lng : null);
            $this->em->flush();
            $this->addFlash('success', 'Salle mise à jour.');

            return $this->redirectToRoute('school_season_rooms', ['id' => $id]);
        }

        return $this->render('school/settings/season/room_form.html.twig', [
            'school' => $school,
            'season' => $season,
            'room'   => $room,
        ]);
    }

    #[Route('/rooms/{roomId}/delete', name: 'school_season_room_delete', methods: ['POST'])]
    public function roomDelete(string $id, string $roomId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $room = $this->em->getRepository(Room::class)->find($roomId);

        if ($room === null || $room->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Salle introuvable.');
        }

        if (!$this->isCsrfTokenValid('room_delete_' . $roomId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('school_season_rooms', ['id' => $id]);
        }

        $room->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Salle supprimée.');

        return $this->redirectToRoute('school_season_rooms', ['id' => $id]);
    }

    // -------------------------------------------------------------------------
    // Addresses
    // -------------------------------------------------------------------------

    #[Route('/addresses', name: 'school_season_addresses', methods: ['GET', 'POST'])]
    public function addresses(string $id, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        if ($request->isMethod('POST')) {
            $address = new Address();
            $address->setSchoolId($school->getId());
            $address->setSeasonId($season->getId());
            $address->setName((string) $request->request->get('name'));
            $address->setAddress((string) $request->request->get('address'));
            $this->em->persist($address);
            $this->em->flush();
            $this->addFlash('success', 'Adresse ajoutée.');

            return $this->redirectToRoute('school_season_addresses', ['id' => $id]);
        }

        $addresses = $this->em->getRepository(Address::class)->findBy(['seasonId' => $season->getId()]);

        return $this->render('school/settings/season/addresses.html.twig', [
            'school'      => $school,
            'season'    => $season,
            'addresses' => $addresses,
        ]);
    }

    // -------------------------------------------------------------------------
    // Levels (niveaux)
    // -------------------------------------------------------------------------

    #[Route('/levels', name: 'school_season_levels', methods: ['GET'])]
    public function levels(string $id): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $levels = $this->em->getRepository(\App\Entity\Level::class)->findBy(
            ['seasonId' => $season->getId(), 'deletedAt' => null],
            ['name' => 'ASC']
        );

        return $this->render('school/settings/season/levels.html.twig', [
            'school'  => $school,
            'season'  => $season,
            'levels'  => $levels,
        ]);
    }

    #[Route('/levels/create', name: 'school_season_level_create', methods: ['POST'])]
    public function levelCreate(string $id, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $name = trim((string) $request->request->get('name'));
        if ($name !== '') {
            $level = new \App\Entity\Level();
            $level->setSchoolId($school->getId());
            $level->setSeasonId($season->getId());
            $level->setName($name);
            $this->em->persist($level);
            $this->em->flush();
            $this->addFlash('success', 'Niveau ajouté.');
        }

        return $this->redirectToRoute('school_season_levels', ['id' => $id]);
    }

    #[Route('/levels/{levelId}/update', name: 'school_season_level_update', methods: ['POST'])]
    public function levelUpdate(string $id, string $levelId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $level = $this->em->getRepository(\App\Entity\Level::class)->find($levelId);

        if ($level === null || $level->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Niveau introuvable.');
        }

        $name = trim((string) $request->request->get('name'));
        if ($name !== '') {
            $level->setName($name);
            $this->em->flush();
            $this->addFlash('success', 'Niveau mis à jour.');
        }

        return $this->redirectToRoute('school_season_levels', ['id' => $id]);
    }

    #[Route('/levels/{levelId}/delete', name: 'school_season_level_delete', methods: ['POST'])]
    public function levelDelete(string $id, string $levelId, Request $request): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);
        $level = $this->em->getRepository(\App\Entity\Level::class)->find($levelId);

        if ($level === null || $level->getSeasonId() !== $season->getId()) {
            throw $this->createNotFoundException('Niveau introuvable.');
        }

        if (!$this->isCsrfTokenValid('level_delete_' . $levelId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token invalide.');
            return $this->redirectToRoute('school_season_levels', ['id' => $id]);
        }

        $level->setDeletedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Niveau supprimé.');

        return $this->redirectToRoute('school_season_levels', ['id' => $id]);
    }

    #[Route('/levels/quick-create', name: 'school_season_level_quick_create', methods: ['POST'])]
    public function levelQuickCreate(string $id, Request $request): \Symfony\Component\HttpFoundation\JsonResponse
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $name = trim((string) $request->request->get('name'));
        if ($name === '') {
            return $this->json(['success' => false, 'error' => 'Nom requis.'], 400);
        }

        $allLevels = $this->em->getRepository(\App\Entity\Level::class)->findBy([
            'seasonId'  => $season->getId(),
            'deletedAt' => null,
        ]);
        $normalizedNew = $this->normalizeLevelName($name);
        foreach ($allLevels as $existing) {
            if ($this->normalizeLevelName($existing->getName()) === $normalizedNew) {
                return $this->json(['success' => false, 'error' => 'Ce niveau existe déjà.'], 409);
            }
        }

        $level = new \App\Entity\Level();
        $level->setSchoolId($school->getId());
        $level->setSeasonId($season->getId());
        $level->setName($name);
        $this->em->persist($level);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $level->getId(), 'name' => $level->getName()]);
    }

    // -------------------------------------------------------------------------
    // Gala
    // -------------------------------------------------------------------------

    #[Route('/gala', name: 'school_season_gala', methods: ['GET'])]
    public function gala(string $id): Response
    {
        [$school, $season] = $this->loadSeasonForAdmin($id);

        $participations = $this->em->getRepository(SchoolProfileGalaParticipation::class)->findBy([
            'seasonId' => $season->getId(),
            'schoolId'   => $school->getId(),
        ]);

        return $this->render('school/settings/season/gala.html.twig', [
            'school'           => $school,
            'season'         => $season,
            'participations' => $participations,
        ]);
    }

    private function normalizeLevelName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = \Normalizer::normalize($name, \Normalizer::NFD);
        $name = preg_replace('/[\x{0300}-\x{036f}]/u', '', $name);
        return trim($name);
    }
}
