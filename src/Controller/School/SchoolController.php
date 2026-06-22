<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Event;
use App\Entity\Season;
use App\Entity\SchoolHomeKpiDaily;
use App\Entity\SchoolProfile;
use App\Entity\Order;
use App\Entity\User;
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
            return $this->redirectToRoute('app_dashboard');
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
            'countAdmins'   => $countMembers(SchoolRole::School),
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
            return $this->redirectToRoute('app_create_school');
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
            return $this->redirectToRoute('app_create_school');
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

}
