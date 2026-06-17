<?php

declare(strict_types=1);

namespace App\Controller\App;

use App\Entity\Profile;
use App\Entity\Team;
use App\Entity\TeamProfile;
use App\Enum\AppRole;
use App\Enum\Gender;
use App\Enum\TeamRole;
use App\Enum\TeamStatus;
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
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $profiles = $user->getProfiles()->filter(fn (Profile $p) => $p->getDeletedAt() === null)->getValues();

        if (empty($profiles)) {
            return $this->redirectToRoute('app_setup_you');
        }

        $profileIds = array_map(fn (Profile $p) => $p->getId(), $profiles);

        /** @var TeamProfile[] $teamProfiles */
        $teamProfiles = $this->em->createQueryBuilder()
            ->select('tp', 't')
            ->from(TeamProfile::class, 'tp')
            ->join('tp.team', 't')
            ->where('tp.profile IN (:profileIds)')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('profileIds', $profileIds)
            ->getQuery()
            ->getResult();

        $teams = array_unique(
            array_map(fn (TeamProfile $tp) => $tp->getTeam(), $teamProfiles),
            SORT_REGULAR
        );

        if (count($teams) === 1) {
            $team = $teams[0];
            $request->getSession()->set('currentTeamId', $team->getId());

            return $this->redirectToRoute('school_home');
        }

        return $this->render('app/home.html.twig', [
            'teams' => $teams,
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
            ->from(TeamProfile::class, 'tp')
            ->where('tp.team = :teamId')
            ->andWhere('tp.profile IN (:profileIds)')
            ->andWhere('tp.deletedAt IS NULL')
            ->setParameter('teamId', $id)
            ->setParameter('profileIds', $profileIds)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($tp === null) {
            throw $this->createAccessDeniedException('Not a member of this school.');
        }

        $request->getSession()->set('currentTeamId', $id);

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

        $errors = [];
        $formData = [
            'first_name' => '',
            'last_name'  => '',
            'dob'        => '',
            'gender'     => '',
            'phone'      => '',
        ];

        if ($request->isMethod('POST')) {
            $formData['first_name'] = trim((string) $request->request->get('first_name', ''));
            $formData['last_name']  = trim((string) $request->request->get('last_name', ''));
            $formData['dob']        = trim((string) $request->request->get('dob', ''));
            $formData['gender']     = trim((string) $request->request->get('gender', ''));
            $formData['phone']      = trim((string) $request->request->get('phone', ''));

            if ($formData['first_name'] === '') {
                $errors['first_name'] = 'Le prénom est obligatoire.';
            }
            if ($formData['last_name'] === '') {
                $errors['last_name'] = 'Le nom est obligatoire.';
            }

            if (empty($errors)) {
                $profile = new Profile();
                $profile->setUser($user);
                $profile->setFirstName($formData['first_name']);
                $profile->setLastName($formData['last_name']);
                $profile->setIsPrimary(true);

                if ($formData['dob'] !== '') {
                    $dob = \DateTimeImmutable::createFromFormat('Y-m-d', $formData['dob']);
                    if ($dob !== false) {
                        $profile->setDob($dob);
                    }
                }

                if ($formData['gender'] !== '') {
                    $gender = Gender::tryFrom($formData['gender']);
                    if ($gender !== null) {
                        $profile->setGender($gender);
                    }
                }

                if ($formData['phone'] !== '') {
                    $profile->setPhone($formData['phone']);
                }

                $this->em->persist($profile);
                $this->em->flush();

                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('app/setup/profile.html.twig', [
            'formData' => $formData,
            'errors'   => $errors,
            'genders'  => Gender::cases(),
        ]);
    }

    #[Route('/setup/create-school', name: 'app_create_school', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function createSchool(Request $request): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $errors = [];
        $formData = [
            'name' => '',
            'type' => '',
        ];

        if ($request->isMethod('POST')) {
            $formData['name'] = trim((string) $request->request->get('name', ''));
            $formData['type'] = trim((string) $request->request->get('type', ''));

            if ($formData['name'] === '') {
                $errors['name'] = 'Le nom de l\'école est obligatoire.';
            }

            if (empty($errors)) {
                $team = new Team();
                $team->setName($formData['name']);

                if ($formData['type'] !== '') {
                    $team->setType($formData['type']);
                }

                $team->setStatus(TeamStatus::Waiting);

                $primaryProfile = $user->getProfiles()->filter(
                    fn (Profile $p) => $p->isPrimary() && $p->getDeletedAt() === null
                )->first();

                if ($primaryProfile !== false) {
                    $teamProfile = new TeamProfile();
                    $teamProfile->setTeam($team);
                    $teamProfile->setProfile($primaryProfile);
                    $teamProfile->setRole(TeamRole::Admin);
                    $this->em->persist($teamProfile);
                }

                $this->em->persist($team);
                $this->em->flush();

                $request->getSession()->set('currentTeamId', $team->getId());

                return $this->redirectToRoute('app_home');
            }
        }

        return $this->render('app/setup/create_school.html.twig', [
            'formData' => $formData,
            'errors'   => $errors,
        ]);
    }
}
