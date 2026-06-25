<?php

declare(strict_types=1);

namespace App\Controller\Teacher;

use App\Entity\SchoolUser;
use App\Entity\SchoolProfilePackage;
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

        $schoolProfiles = $this->em->createQueryBuilder()
            ->select('sp', 's')
            ->from(SchoolUser::class, 'sp')
            ->join('sp.school', 's')
            ->where('sp.user = :user')
            ->andWhere('sp.role = :role')
            ->andWhere('sp.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('role', SchoolRole::Teacher)
            ->getQuery()
            ->getResult();

        return $this->render('teacher/home.html.twig', [
            'schoolProfiles' => $schoolProfiles,
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
