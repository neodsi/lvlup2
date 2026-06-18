<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Event;
use App\Entity\Season;
use App\Entity\SchoolHomeKpiDaily;
use App\Entity\SchoolProfile;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\Order;
use App\Entity\PaymentSchedule;
use App\Entity\SchoolProfileGalaParticipation;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\SchoolRole;
use App\Security\Voter\SchoolVoter;
use App\Service\Event\EventService;
use App\Service\SchoolContextService;
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
        private readonly SchoolContextService $schoolContext,
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
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            return $this->redirectToRoute('app_home');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $kpis = $this->em->getRepository(SchoolHomeKpiDaily::class)->findBy(
            ['schoolId' => $school->getId()],
            ['date' => 'DESC'],
            30,
        );

        $countMembers = fn(SchoolRole $role) => (int) $this->em->createQueryBuilder()
            ->select('COUNT(tp.id)')
            ->from(SchoolProfile::class, 'tp')
            ->where('tp.school = :school')
            ->andWhere('tp.role = :role')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('school', $school)
            ->setParameter('role', $role)
            ->getQuery()->getSingleScalarResult();

        $countOrders = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.schoolId = :schoolId')
            ->setParameter('schoolId', $school->getId())
            ->getQuery()->getSingleScalarResult();

        $currentSeasonId = $school->getCurrentSeasonId();
        $countCours = $currentSeasonId ? (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')
            ->from(Event::class, 'e')
            ->where('e.schoolId = :schoolId')
            ->andWhere('e.seasonId = :seasonId')
            ->setParameter('schoolId', $school->getId())
            ->setParameter('seasonId', $currentSeasonId)
            ->getQuery()->getSingleScalarResult() : 0;

        return $this->render('school/home.html.twig', [
            'school'         => $school,
            'kpis'         => $kpis,
            'countStudents' => $countMembers(SchoolRole::Student),
            'countTeachers' => $countMembers(SchoolRole::Teacher),
            'countAdmins'   => $countMembers(SchoolRole::Admin),
            'countOrders'   => $countOrders,
            'countCours'    => $countCours,
        ]);
    }

    #[Route('/edit', name: 'school_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::UPDATE, $school);

        if ($request->isMethod('POST')) {
            $school->setName((string) $request->request->get('name', $school->getName()));
            $school->setType($request->request->get('type'));
            $school->setInvoicePrefix($request->request->get('invoicePrefix'));
            $school->setInvoiceAddress($request->request->get('invoiceAddress'));

            $nbStart = $request->request->get('invoiceNumberingStart');
            if ($nbStart !== null) {
                $school->setInvoiceNumberingStart((int) $nbStart);
            }

            $this->em->flush();
            $this->addFlash('success', 'Informations mises à jour.');

            return $this->redirectToRoute('school_edit');
        }

        return $this->render('school/edit.html.twig', ['school' => $school]);
    }

    #[Route('/events', name: 'school_events', methods: ['GET'])]
    public function events(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $season = $school->getCurrentSeasonId()
            ? $this->em->getRepository(Season::class)->find($school->getCurrentSeasonId())
            : null;

        $events = $season
            ? $this->em->getRepository(\App\Entity\Event::class)->findBy(['seasonId' => $season->getId()])
            : [];

        return $this->render('school/events.html.twig', [
            'school'   => $school,
            'season' => $season,
            'events' => $events,
        ]);
    }

    #[Route('/fast-count', name: 'school_fast_count', methods: ['GET'])]
    public function fastCount(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $packages = $this->em->createQueryBuilder()
            ->select('tpp')
            ->from(SchoolProfilePackage::class, 'tpp')
            ->where('tpp.schoolId = :schoolId')
            ->andWhere('tpp.type = :type')
            ->andWhere('tpp.deletedAt IS NULL')
            ->setParameter('schoolId', $school->getId())
            ->setParameter('type', 'a_la_carte')
            ->getQuery()
            ->getResult();

        return $this->render('school/fast_count.html.twig', [
            'school'     => $school,
            'packages' => $packages,
        ]);
    }

    #[Route('/my/{event_type}', name: 'school_my_courses', methods: ['GET'])]
    public function myCourses(string $event_type): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $type = EventType::tryFrom($event_type);

        $qb = $this->em->createQueryBuilder()
            ->select('eop', 'eo')
            ->from(\App\Entity\EventOccurenceProfile::class, 'eop')
            ->join(\App\Entity\EventOccurence::class, 'eo', 'WITH', 'eo.id = eop.occurenceId')
            ->join(\App\Entity\Event::class, 'e', 'WITH', 'e.id = eo.eventId')
            ->where('eop.schoolProfileId = :tpId')
            ->andWhere('e.schoolId = :schoolId')
            ->setParameter('tpId', $schoolProfile->getId())
            ->setParameter('schoolId', $school->getId())
            ->orderBy('eo.occurenceAt', 'ASC');

        if ($type !== null) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }

        $occurrences = $qb->getQuery()->getResult();

        return $this->render('school/my/courses.html.twig', [
            'school'        => $school,
            'event_type'  => $event_type,
            'occurrences' => $occurrences,
        ]);
    }

    #[Route('/my/gala', name: 'school_my_gala', methods: ['GET'])]
    public function myGala(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $participations = $this->em->getRepository(SchoolProfileGalaParticipation::class)->findBy([
            'schoolProfileId' => $schoolProfile->getId(),
        ]);

        return $this->render('school/my/gala.html.twig', [
            'school'           => $school,
            'participations' => $participations,
        ]);
    }

    #[Route('/my/packages', name: 'school_my_packages', methods: ['GET'])]
    public function myPackages(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $packages = $this->em->getRepository(SchoolProfilePackage::class)->findBy([
            'schoolProfileId' => $schoolProfile->getId(),
            'schoolId'        => $school->getId(),
        ]);

        return $this->render('school/my/packages.html.twig', [
            'school'     => $school,
            'packages' => $packages,
        ]);
    }

    #[Route('/my/payment-schedules', name: 'school_my_payment_schedules', methods: ['GET'])]
    public function myPaymentSchedules(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $schedules = $this->em->createQueryBuilder()
            ->select('ps')
            ->from(PaymentSchedule::class, 'ps')
            ->join(Order::class, 'o', 'WITH', 'o.id = ps.orderId')
            ->where('o.schoolProfileId = :tpId')
            ->andWhere('ps.schoolId = :schoolId')
            ->setParameter('tpId', $schoolProfile->getId())
            ->setParameter('schoolId', $school->getId())
            ->orderBy('ps.dueAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->render('school/my/payment_schedules.html.twig', [
            'school'      => $school,
            'schedules' => $schedules,
        ]);
    }

    #[Route('/my/season', name: 'school_my_season', methods: ['GET'])]
    public function mySeason(): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            throw $this->createAccessDeniedException('Not a school member.');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $seasonId        = $school->getCurrentSeasonId();
        $season          = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;
        $schoolProfileSeason = $seasonId
            ? $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'schoolProfileId' => $schoolProfile->getId(),
                'seasonId'      => $seasonId,
            ])
            : null;

        return $this->render('school/my/season.html.twig', [
            'school'              => $school,
            'season'            => $season,
            'schoolProfileSeason' => $schoolProfileSeason,
        ]);
    }
}
