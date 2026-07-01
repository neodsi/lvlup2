<?php

declare(strict_types=1);

namespace App\Controller\Student;

use App\Entity\EventOccurence;
use App\Entity\EventOccurenceProfile;
use App\Entity\Event;
use App\Entity\PaymentSchedule;
use App\Entity\Order;
use App\Entity\SchoolProfileGalaParticipation;
use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\Season;
use App\Entity\User;
use App\Enum\EventType;
use App\Enum\SchoolRole;
use App\Security\Voter\SchoolVoter;
use App\Service\SchoolContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/student')]
#[IsGranted('ROLE_USER')]
final class MyController extends AbstractController
{
    public function __construct(
        private readonly SchoolContextService $schoolContext,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/home', name: 'student_home', methods: ['GET'])]
    public function home(): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $profile = $user->getProfile();

        $schools = [];

        if ($profile !== null) {
            $schoolIds = $this->em->createQuery(
                'SELECT DISTINCT sps.schoolId FROM App\Entity\SchoolProfileSeason sps
                 WHERE sps.profileId = :profileId AND sps.role = :role'
            )
            ->setParameter('profileId', $profile->getId())
            ->setParameter('role', SchoolRole::Student)
            ->getSingleColumnResult();

            if (!empty($schoolIds)) {
                $schools = $this->em->createQuery(
                    'SELECT s FROM App\Entity\School s WHERE s.id IN (:ids) ORDER BY s.name ASC'
                )
                ->setParameter('ids', $schoolIds)
                ->getResult();
            }
        }

        return $this->render('student/home.html.twig', [
            'schools' => $schools,
        ]);
    }

    #[Route('/gala', name: 'school_my_gala', methods: ['GET'])]
    public function myGala(): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $school  = $this->schoolContext->getCurrentSchool();
        $member  = $this->schoolContext->getCurrentSchoolMember($user);

        if ($school === null || $member === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $profile = $user->getProfile();
        $participations = $profile !== null
            ? $this->em->getRepository(SchoolProfileGalaParticipation::class)->findBy([
                'profileId' => $profile->getId(),
            ])
            : [];

        return $this->render('school/my/gala.html.twig', [
            'school'         => $school,
            'participations' => $participations,
        ]);
    }

    #[Route('/packages', name: 'school_my_packages', methods: ['GET'])]
    public function myPackages(): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();
        $member = $this->schoolContext->getCurrentSchoolMember($user);

        if ($school === null || $member === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $profile  = $user->getProfile();
        $packages = $profile !== null
            ? $this->em->getRepository(SchoolProfilePackage::class)->findBy(
                ['profileId' => $profile->getId(), 'schoolId' => $school->getId()],
                ['createdAt' => 'DESC']
            )
            : [];

        // Load Package definitions for names
        $packageIds = array_unique(array_filter(array_map(
            fn(SchoolProfilePackage $p) => $p->getPackageId(),
            $packages
        )));
        $packageById = [];
        if (!empty($packageIds)) {
            $pkgDefs = $this->em->createQuery(
                'SELECT p FROM App\Entity\Package p WHERE p.id IN (:ids)'
            )->setParameter('ids', $packageIds)->getResult();
            foreach ($pkgDefs as $pkgDef) {
                $packageById[$pkgDef->getId()] = $pkgDef;
            }
        }

        return $this->render('school/my/packages.html.twig', [
            'school'      => $school,
            'packages'    => $packages,
            'packageById' => $packageById,
        ]);
    }

    #[Route('/payment-schedules', name: 'school_my_payment_schedules', methods: ['GET'])]
    public function myPaymentSchedules(): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();
        $member = $this->schoolContext->getCurrentSchoolMember($user);

        if ($school === null || $member === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $profile   = $user->getProfile();
        $schedules = [];

        if ($profile !== null) {
            $schedules = $this->em->createQueryBuilder()
                ->select('ps')
                ->from(PaymentSchedule::class, 'ps')
                ->join(Order::class, 'o', 'WITH', 'o.id = ps.orderId')
                ->where('o.profileId = :profileId')
                ->andWhere('ps.schoolId = :schoolId')
                ->setParameter('profileId', $profile->getId())
                ->setParameter('schoolId', $school->getId())
                ->orderBy('ps.dueAt', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->render('school/my/payment_schedules.html.twig', [
            'school'    => $school,
            'schedules' => $schedules,
        ]);
    }

    #[Route('/season', name: 'school_my_season', methods: ['GET'])]
    public function mySeason(): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();
        $member = $this->schoolContext->getCurrentSchoolMember($user);

        if ($school === null || $member === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $profile             = $user->getProfile();
        $seasonId            = $school->getCurrentSeasonId();
        $season              = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;
        $schoolProfileSeason = ($seasonId !== null && $profile !== null)
            ? $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'profileId' => $profile->getId(),
                'seasonId'  => $seasonId,
            ])
            : null;

        return $this->render('school/my/season.html.twig', [
            'school'              => $school,
            'season'              => $season,
            'schoolProfileSeason' => $schoolProfileSeason,
        ]);
    }

    #[Route('/{event_type}', name: 'school_my_courses', methods: ['GET'])]
    public function myCourses(string $event_type): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();
        $member = $this->schoolContext->getCurrentSchoolMember($user);

        if ($school === null || $member === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $profile = $user->getProfile();
        $type    = EventType::tryFrom($event_type);

        $occurrences = [];

        if ($profile !== null) {
            $qb = $this->em->createQueryBuilder()
                ->select('eop', 'eo')
                ->from(EventOccurenceProfile::class, 'eop')
                ->join(EventOccurence::class, 'eo', 'WITH', 'eo.id = eop.occurenceId')
                ->join(Event::class, 'e', 'WITH', 'e.id = eo.eventId')
                ->where('eop.profileId = :profileId')
                ->andWhere('e.schoolId = :schoolId')
                ->setParameter('profileId', $profile->getId())
                ->setParameter('schoolId', $school->getId())
                ->orderBy('eo.occurenceAt', 'ASC');

            if ($type !== null) {
                $qb->andWhere('e.type = :type')->setParameter('type', $type);
            }

            $occurrences = $qb->getQuery()->getResult();
        }

        return $this->render('school/my/courses.html.twig', [
            'school'      => $school,
            'event_type'  => $event_type,
            'occurrences' => $occurrences,
        ]);
    }
}
