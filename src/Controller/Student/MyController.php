<?php

declare(strict_types=1);

namespace App\Controller\Student;

use App\Entity\EventOccurence;
use App\Entity\EventOccurenceProfile;
use App\Entity\Event;
use App\Entity\PaymentSchedule;
use App\Entity\Order;
use App\Entity\Profile;
use App\Entity\SchoolProfile;
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
        $user = $this->getUser();

        $profileIds = $user->getProfiles()
            ->filter(fn(Profile $p) => $p->getDeletedAt() === null)
            ->map(fn(Profile $p) => $p->getId())
            ->getValues();

        $schoolProfiles = empty($profileIds) ? [] : $this->em->createQueryBuilder()
            ->select('sp', 's')
            ->from(SchoolProfile::class, 'sp')
            ->join('sp.school', 's')
            ->where('sp.profile IN (:profileIds)')
            ->andWhere('sp.role = :role')
            ->andWhere('sp.deletedAt IS NULL')
            ->setParameter('profileIds', $profileIds)
            ->setParameter('role', SchoolRole::Student)
            ->getQuery()
            ->getResult();

        return $this->render('student/home.html.twig', [
            'schoolProfiles' => $schoolProfiles,
        ]);
    }

    #[Route('/gala', name: 'school_my_gala', methods: ['GET'])]
    public function myGala(): Response
    {
        /** @var User $user */
        $user          = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $participations = $this->em->getRepository(SchoolProfileGalaParticipation::class)->findBy([
            'schoolProfileId' => $schoolProfile->getId(),
        ]);

        return $this->render('school/my/gala.html.twig', [
            'school'         => $school,
            'participations' => $participations,
        ]);
    }

    #[Route('/packages', name: 'school_my_packages', methods: ['GET'])]
    public function myPackages(): Response
    {
        /** @var User $user */
        $user          = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $packages = $this->em->getRepository(SchoolProfilePackage::class)->findBy([
            'schoolProfileId' => $schoolProfile->getId(),
            'schoolId'        => $school->getId(),
        ]);

        return $this->render('school/my/packages.html.twig', [
            'school'   => $school,
            'packages' => $packages,
        ]);
    }

    #[Route('/payment-schedules', name: 'school_my_payment_schedules', methods: ['GET'])]
    public function myPaymentSchedules(): Response
    {
        /** @var User $user */
        $user          = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            return $this->redirectToRoute('app_create_school');
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
            'school'    => $school,
            'schedules' => $schedules,
        ]);
    }

    #[Route('/season', name: 'school_my_season', methods: ['GET'])]
    public function mySeason(): Response
    {
        /** @var User $user */
        $user          = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $seasonId            = $school->getCurrentSeasonId();
        $season              = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;
        $schoolProfileSeason = $seasonId
            ? $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'schoolProfileId' => $schoolProfile->getId(),
                'seasonId'        => $seasonId,
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
        $user          = $this->getUser();
        $school        = $this->schoolContext->getCurrentSchool();
        $schoolProfile = $this->schoolContext->getCurrentSchoolProfile($user);

        if ($school === null || $schoolProfile === null) {
            return $this->redirectToRoute('app_create_school');
        }

        $this->denyAccessUnlessGranted(SchoolVoter::VIEW, $school);

        $type = EventType::tryFrom($event_type);

        $qb = $this->em->createQueryBuilder()
            ->select('eop', 'eo')
            ->from(EventOccurenceProfile::class, 'eop')
            ->join(EventOccurence::class, 'eo', 'WITH', 'eo.id = eop.occurenceId')
            ->join(Event::class, 'e', 'WITH', 'e.id = eo.eventId')
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
            'school'      => $school,
            'event_type'  => $event_type,
            'occurrences' => $occurrences,
        ]);
    }
}
