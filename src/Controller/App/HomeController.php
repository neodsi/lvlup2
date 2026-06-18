<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Profile;
use App\Entity\School;
use App\Entity\SchoolProfile;
use App\Enum\Gender;
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

    #[Route('/home', name: 'app_home')]
    #[IsGranted('ROLE_USER')]
    public function home(Request $request): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_admin');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $profiles = $user->getProfiles()->filter(fn (Profile $p) => $p->getDeletedAt() === null)->getValues();

        if (empty($profiles)) {
            return $this->redirectToRoute('app_setup_you');
        }

        if ($this->isGranted('ROLE_SCHOOL')) {
            $profileIds = array_map(fn (Profile $p) => $p->getId(), $profiles);

            /** @var SchoolProfile|null $schoolProfile */
            $schoolProfile = $this->em->createQueryBuilder()
                ->select('tp', 't')
                ->from(SchoolProfile::class, 'tp')
                ->join('tp.school', 't')
                ->where('tp.profile IN (:profileIds)')
                ->andWhere('tp.deletedAt IS NULL')
                ->setParameter('profileIds', $profileIds)
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($schoolProfile === null) {
                return $this->redirectToRoute('app_create_school');
            }

            $request->getSession()->set('currentSchoolId', (string) $schoolProfile->getSchool()->getId());

            return $this->redirectToRoute('school_home');
        }

        return $this->render('app/home.html.twig', [
            'schools' => [],
        ]);
    }

    #[Route('/select-school/{id}', name: 'app_select_school')]
    #[IsGranted('ROLE_USER')]
    public function selectSchool(string $id, Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $profiles = $user->getProfiles()->filter(fn (Profile $p) => $p->getDeletedAt() === null)->getValues();
        $profileIds = array_map(fn (Profile $p) => $p->getId(), $profiles);

        $tp = $this->em->createQueryBuilder()
            ->select('tp')
            ->from(SchoolProfile::class, 'tp')
            ->where('tp.school = :schoolId')
            ->andWhere('tp.profile IN (:profileIds)')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('schoolId', $id)
            ->setParameter('profileIds', $profileIds)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($tp === null) {
            throw $this->createAccessDeniedException('Not a member of this school.');
        }

        $request->getSession()->set('currentSchoolId', $id);

        return $this->redirectToRoute('school_home');
    }

    #[Route('/no-school', name: 'app_no_school')]
    public function noSchool(): Response
    {
        return $this->render('app/no_school.html.twig');
    }

    #[Route('/setup/you', name: 'app_setup_you')]
    #[IsGranted('ROLE_USER')]
    public function setupYou(): Response
    {
        return $this->render('app/setup/you.html.twig');
    }

    #[Route('/setup/profile', name: 'app_setup_profile', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function setupProfile(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $hasPrimary = $user->getProfiles()->filter(
            fn (Profile $p) => $p->isPrimary() && $p->getDeletedAt() === null
        )->count() > 0;

        if ($hasPrimary) {
            return $this->redirectToRoute('app_home');
        }

        $form = $this->createForm(SetupProfileType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $profile = new Profile();
            $profile->setUser($user);
            $profile->setFirstName($data['firstName']);
            $profile->setLastName($data['lastName']);
            $profile->setIsPrimary(true);

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

            return $this->redirectToRoute('app_home');
        }

        return $this->render('app/setup/profile.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/setup/create-school', name: 'app_create_school', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function createSchool(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

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

            $primaryProfile = $user->getProfiles()->filter(
                fn (Profile $p) => $p->isPrimary() && $p->getDeletedAt() === null
            )->first();

            if ($primaryProfile !== false) {
                $schoolProfile = new SchoolProfile();
                $schoolProfile->setSchool($school);
                $schoolProfile->setProfile($primaryProfile);
                $schoolProfile->setRole(SchoolRole::School);
                $this->em->persist($schoolProfile);
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
