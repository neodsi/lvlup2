<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Event;
use App\Entity\Season;
use App\Entity\TeamHomeKpiDaily;
use App\Entity\TeamProfile;
use App\Entity\TeamProfilePackage;
use App\Entity\TeamProfileSeason;
use App\Entity\Order;
use App\Entity\PaymentSchedule;
use App\Entity\TeamProfileGalaParticipation;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\TeamRole;
use App\Security\Voter\TeamVoter;
use App\Service\Event\EventService;
use App\Service\TeamContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/school')]
#[IsGranted('ROLE_USER')]
final class SchoolController extends AbstractController
{
    public function __construct(
        private readonly TeamContextService $teamContext,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'school_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('school_home');
    }

    #[Route('/home', name: 'school_home', methods: ['GET'])]
    public function home(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            return $this->redirectToRoute('app_home');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $kpis = $this->em->getRepository(TeamHomeKpiDaily::class)->findBy(
            ['teamId' => $team->getId()],
            ['date' => 'DESC'],
            30,
        );

        $countMembers = fn(TeamRole $role) => (int) $this->em->createQueryBuilder()
            ->select('COUNT(tp.id)')
            ->from(TeamProfile::class, 'tp')
            ->where('tp.team = :team')
            ->andWhere('tp.role = :role')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('team', $team)
            ->setParameter('role', $role)
            ->getQuery()->getSingleScalarResult();

        $countOrders = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.teamId = :teamId')
            ->setParameter('teamId', $team->getId())
            ->getQuery()->getSingleScalarResult();

        $currentSeasonId = $team->getCurrentSeasonId();
        $countCours = $currentSeasonId ? (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Event::class, 'e')
            ->where('e.teamId = :teamId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('teamId', $team->getId())
            ->setParameter('seasonId', $currentSeasonId)
            ->getQuery()->getSingleScalarResult() : 0;

        return $this->render('school/home.html.twig', [
            'team'         => $team,
            'kpis'         => $kpis,
            'countStudents' => $countMembers(TeamRole::TeamStudent),
            'countTeachers' => $countMembers(TeamRole::TeamTeacher),
            'countAdmins'   => $countMembers(TeamRole::TeamAdmin),
            'countOrders'   => $countOrders,
            'countCours'    => $countCours,
        ]);
    }

    #[Route('/edit', name: 'school_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        if ($request->isMethod('POST')) {
            $team->setName((string) $request->request->get('name', $team->getName()));
            $team->setType($request->request->get('type'));
            $team->setInvoicePrefix($request->request->get('invoicePrefix'));
            $team->setInvoiceAddress($request->request->get('invoiceAddress'));

            $nbStart = $request->request->get('invoiceNumberingStart');
            if ($nbStart !== null) {
                $team->setInvoiceNumberingStart((int) $nbStart);
            }

            $this->em->flush();
            $this->addFlash('success', 'Informations mises à jour.');

            return $this->redirectToRoute('school_edit');
        }

        return $this->render('school/edit.html.twig', ['team' => $team]);
    }

    #[Route('/events', name: 'school_events', methods: ['GET'])]
    public function events(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $season = $team->getCurrentSeasonId()
            ? $this->em->getRepository(Season::class)->find($team->getCurrentSeasonId())
            : null;

        $events = $season
            ? $this->em->getRepository(\App\Entity\Event::class)->findBy(['seasonId' => $season->getId()])
            : [];

        return $this->render('school/events.html.twig', [
            'team'   => $team,
            'season' => $season,
            'events' => $events,
        ]);
    }

    #[Route('/fast-count', name: 'school_fast_count', methods: ['GET'])]
    public function fastCount(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $packages = $this->em->createQueryBuilder()
            ->select('tpp')
            ->from(TeamProfilePackage::class, 'tpp')
            ->where('tpp.teamId = :teamId')
            ->andWhere('tpp.type = :type')
            ->andWhere('tpp.deletedAt IS NULL')
            ->setParameter('teamId', $team->getId())
            ->setParameter('type', 'a_la_carte')
            ->getQuery()
            ->getResult();

        return $this->render('school/fast_count.html.twig', [
            'team'     => $team,
            'packages' => $packages,
        ]);
    }

    #[Route('/my/{event_type}', name: 'school_my_courses', methods: ['GET'])]
    public function myCourses(string $event_type): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $team        = $this->teamContext->getCurrentTeam();
        $teamProfile = $this->teamContext->getCurrentTeamProfile($user);

        if ($team === null || $teamProfile === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $type = EventType::tryFrom($event_type);

        $qb = $this->em->createQueryBuilder()
            ->select('eop', 'eo')
            ->from(\App\Entity\EventOccurenceProfile::class, 'eop')
            ->join(\App\Entity\EventOccurence::class, 'eo', 'WITH', 'eo.id = eop.occurenceId')
            ->join(\App\Entity\Event::class, 'e', 'WITH', 'e.id = eo.eventId')
            ->where('eop.teamProfileId = :tpId')
            ->andWhere('e.teamId = :teamId')
            ->setParameter('tpId', $teamProfile->getId())
            ->setParameter('teamId', $team->getId())
            ->orderBy('eo.occurenceAt', 'ASC');

        if ($type !== null) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }

        $occurrences = $qb->getQuery()->getResult();

        return $this->render('school/my/courses.html.twig', [
            'team'        => $team,
            'event_type'  => $event_type,
            'occurrences' => $occurrences,
        ]);
    }

    #[Route('/my/gala', name: 'school_my_gala', methods: ['GET'])]
    public function myGala(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $team        = $this->teamContext->getCurrentTeam();
        $teamProfile = $this->teamContext->getCurrentTeamProfile($user);

        if ($team === null || $teamProfile === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $participations = $this->em->getRepository(TeamProfileGalaParticipation::class)->findBy([
            'teamProfileId' => $teamProfile->getId(),
        ]);

        return $this->render('school/my/gala.html.twig', [
            'team'           => $team,
            'participations' => $participations,
        ]);
    }

    #[Route('/my/packages', name: 'school_my_packages', methods: ['GET'])]
    public function myPackages(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $team        = $this->teamContext->getCurrentTeam();
        $teamProfile = $this->teamContext->getCurrentTeamProfile($user);

        if ($team === null || $teamProfile === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $packages = $this->em->getRepository(TeamProfilePackage::class)->findBy([
            'teamProfileId' => $teamProfile->getId(),
            'teamId'        => $team->getId(),
        ]);

        return $this->render('school/my/packages.html.twig', [
            'team'     => $team,
            'packages' => $packages,
        ]);
    }

    #[Route('/my/payment-schedules', name: 'school_my_payment_schedules', methods: ['GET'])]
    public function myPaymentSchedules(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $team        = $this->teamContext->getCurrentTeam();
        $teamProfile = $this->teamContext->getCurrentTeamProfile($user);

        if ($team === null || $teamProfile === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $schedules = $this->em->createQueryBuilder()
            ->select('ps')
            ->from(PaymentSchedule::class, 'ps')
            ->join(Order::class, 'o', 'WITH', 'o.id = ps.orderId')
            ->where('o.teamProfileId = :tpId')
            ->andWhere('ps.teamId = :teamId')
            ->setParameter('tpId', $teamProfile->getId())
            ->setParameter('teamId', $team->getId())
            ->orderBy('ps.dueAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('school/my/payment_schedules.html.twig', [
            'team'      => $team,
            'schedules' => $schedules,
        ]);
    }

    #[Route('/my/season', name: 'school_my_season', methods: ['GET'])]
    public function mySeason(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $team        = $this->teamContext->getCurrentTeam();
        $teamProfile = $this->teamContext->getCurrentTeamProfile($user);

        if ($team === null || $teamProfile === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $seasonId        = $team->getCurrentSeasonId();
        $season          = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;
        $teamProfileSeason = $seasonId
            ? $this->em->getRepository(TeamProfileSeason::class)->findOneBy([
                'teamProfileId' => $teamProfile->getId(),
                'seasonId'      => $seasonId,
            ])
            : null;

        return $this->render('school/my/season.html.twig', [
            'team'              => $team,
            'season'            => $season,
            'teamProfileSeason' => $teamProfileSeason,
        ]);
    }
}
