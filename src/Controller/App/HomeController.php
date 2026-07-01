<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Profile;
use App\Entity\School;
use App\Entity\SchoolProfileSeason;
use App\Enum\Gender;
use App\Enum\SchoolProfileStatus;
use App\Enum\SchoolRole;
use App\Enum\SchoolStatus;
use App\Form\App\CreateSchoolType;
use App\Form\App\SetupProfileType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class HomeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/home', name: 'app_dashboard')]
    #[IsGranted('ROLE_USER')]
    public function home(Request $request): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($user->getProfile() === null) {
            return $this->redirectToRoute('app_setup_you');
        }

        if ($this->isGranted('ROLE_SCHOOL')) {
            $profile = $user->getProfile();

            // Find a school owned by this user
            $school = $profile !== null
                ? $this->em->createQueryBuilder()
                    ->select('s')
                    ->from(School::class, 's')
                    ->where('s.ownerProfileId = :profileId')
                    ->andWhere('s.deletedAt IS NULL')
                    ->setMaxResults(1)
                    ->setParameter('profileId', $profile->getId())
                    ->getQuery()
                    ->getOneOrNullResult()
                : null;

            // Fall back to any school via SchoolProfileSeason with School role
            if ($school === null && $profile !== null) {
                $sps = $this->em->createQueryBuilder()
                    ->select('sps')
                    ->from(SchoolProfileSeason::class, 'sps')
                    ->where('sps.profileId = :profileId')
                    ->andWhere('sps.role = :role')
                    ->orderBy('sps.createdAt', 'DESC')
                    ->setMaxResults(1)
                    ->setParameter('profileId', $profile->getId())
                    ->setParameter('role', SchoolRole::School)
                    ->getQuery()
                    ->getOneOrNullResult();

                if ($sps !== null) {
                    $school = $this->em->getRepository(School::class)->find($sps->getSchoolId());
                }
            }

            if ($school === null) {
                return $this->redirectToRoute('app_create_school');
            }

            $request->getSession()->set('currentSchoolId', (string) $school->getId());

            return $this->redirectToRoute('school_home');
        }

        if ($this->isGranted('ROLE_TEACHER')) {
            return $this->redirectToRoute('teacher_home');
        }

        return $this->redirectToRoute('student_home');
    }

    #[Route('/school/select/{id}', name: 'app_select_school')]
    #[IsGranted('ROLE_USER')]
    public function selectSchool(string $id, Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user    = $this->getUser();
        $profile = $user->getProfile();

        if ($profile === null) {
            throw $this->createAccessDeniedException('No profile.');
        }

        // Find the user's role in this school (any season)
        $school = $this->em->getRepository(School::class)->find($id);
        if ($school === null) {
            throw $this->createNotFoundException('School not found.');
        }

        // Check ownership first
        if ($school->getOwnerProfileId() === $profile->getId()) {
            $request->getSession()->set('currentSchoolId', $id);
            return $this->redirectToRoute('school_home');
        }

        // Find any SchoolProfileSeason for this profile+school
        $sps = $this->em->createQueryBuilder()
            ->select('sps')
            ->from(SchoolProfileSeason::class, 'sps')
            ->where('sps.profileId = :profileId')
            ->andWhere('sps.schoolId = :schoolId')
            ->orderBy('sps.createdAt', 'DESC')
            ->setMaxResults(1)
            ->setParameter('profileId', $profile->getId())
            ->setParameter('schoolId', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if ($sps === null) {
            throw $this->createAccessDeniedException('Not a member of this school.');
        }

        $request->getSession()->set('currentSchoolId', $id);

        return match ($sps->getRole()) {
            SchoolRole::Teacher => $this->redirectToRoute('teacher_fast_count'),
            SchoolRole::Student => $this->redirectToRoute('student_home'),
            default             => $this->redirectToRoute('school_home'),
        };
    }

    #[Route('/school/no-school', name: 'app_no_school')]
    public function noSchool(): Response
    {
        return $this->render('app/no_school.html.twig');
    }

    #[Route('/school/setup/you', name: 'app_setup_you')]
    #[IsGranted('ROLE_USER')]
    public function setupYou(): Response
    {
        return $this->render('app/setup/you.html.twig');
    }

    #[Route('/school/setup/profile', name: 'app_setup_profile', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function setupProfile(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($user->getProfile() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        $form = $this->createForm(SetupProfileType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $profile = new Profile();
            $profile->setUser($user);
            $profile->setFirstName($data['firstName']);
            $profile->setLastName($data['lastName']);

            if ($data['dob'] !== null) {
                $profile->setDob($data['dob']);
            }

            if ($data['gender'] !== null && $data['gender'] !== '') {
                $gender = Gender::tryFrom($data['gender']);
                if ($gender !== null) {
                    $profile->setGender($gender);
                }
            }

            if ($data['phone'] !== null && $data['phone'] !== '') {
                $profile->setPhone($data['phone']);
            }

            $this->em->persist($profile);
            $this->em->flush();

            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('app/setup/profile.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/school/setup/create-school', name: 'app_create_school', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function createSchool(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        if ($user->getProfile() === null) {
            return $this->redirectToRoute('app_setup_you');
        }

        $form = $this->createForm(CreateSchoolType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $school = new School();
            $school->setName($data['name']);

            if ($data['type'] !== null && $data['type'] !== '') {
                $school->setType($data['type']);
            }

            $school->setStatus(SchoolStatus::Accepted);

            $primaryProfile = $user->getProfile();

            if ($primaryProfile !== null) {
                $school->setOwnerProfileId($primaryProfile->getId());
            }

            if (!in_array('ROLE_SCHOOL', $user->getRoles(), true)) {
                $roles   = array_values(array_diff($user->getRoles(), ['ROLE_USER']));
                $roles[] = 'ROLE_SCHOOL';
                $user->setRoles(array_values(array_unique($roles)));
            }

            $this->em->persist($school);
            $this->em->flush();

            $request->getSession()->set('currentSchoolId', $school->getId());

            return $this->redirectToRoute('school_home');
        }

        return $this->render('app/setup/create_school.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}
