<?php

declare(strict_types=1);

namespace App\Controller\Teacher;

use App\Entity\SchoolProfilePackage;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Security\Voter\SchoolVoter;
use App\Service\SchoolContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/teacher')]
#[IsGranted('ROLE_TEACHER')]
final class TeacherController extends AbstractController
{
    public function __construct(
        private readonly SchoolContextService $schoolContext,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'teacher_home', methods: ['GET'])]
    public function home(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $profile = $user->getProfile();

        $schools = [];

        if ($profile !== null) {
            $schoolIds = $this->em->createQuery(
                'SELECT DISTINCT sps.schoolId FROM App\Entity\SchoolProfileSeason sps
                 WHERE sps.profileId = :profileId AND sps.role = :role'
            )
            ->setParameter('profileId', $profile->getId())
            ->setParameter('role', SchoolRole::Teacher)
            ->getSingleColumnResult();

            if (!empty($schoolIds)) {
                $schools = $this->em->createQuery(
                    'SELECT s FROM App\Entity\School s WHERE s.id IN (:ids) ORDER BY s.name ASC'
                )
                ->setParameter('ids', $schoolIds)
                ->getResult();
            }
        }

        return $this->render('teacher/home.html.twig', [
            'schools' => $schools,
        ]);
    }

    #[Route('/fast-count', name: 'teacher_fast_count', methods: ['GET'])]
    public function fastCount(): Response
    {
        /** @var User $user */
        $user   = $this->getUser();
        $school = $this->schoolContext->getCurrentSchool();

        if ($school === null || $this->schoolContext->getCurrentSchoolUser($user) === null) {
            return $this->redirectToRoute('app_create_school');
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
            'school'   => $school,
            'packages' => $packages,
        ]);
    }
}
