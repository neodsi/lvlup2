<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Season;
use App\Entity\TeamProfile;
use App\Entity\TeamProfileSeason;
use App\Entity\User;
use App\Enum\Gender;
use App\Enum\RegistrationStatus;
use App\Enum\TeamRole;
use App\Security\Voter\TeamVoter;
use App\Service\Member\MemberService;
use App\Service\TeamContextService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/school/members')]
#[IsGranted('ROLE_USER')]
final class MemberController extends AbstractController
{
    public function __construct(
        private readonly TeamContextService $teamContext,
        private readonly EntityManagerInterface $em,
        private readonly MemberService $memberService,
    ) {
    }

    #[Route('/{type}', name: 'school_members_list', methods: ['GET'],
        requirements: ['type' => 'all|students|teachers|admins'])]
    public function list(string $type, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $session  = $request->getSession();
        $seasonId = $request->query->get('season');

        if ($seasonId !== null) {
            $season = $this->em->getRepository(Season::class)->find($seasonId);
            if ($season !== null && $season->getTeamId() !== $team->getId()) {
                $season = null;
            }
            if ($season !== null) {
                $session->set('school.season_id', $season->getId());
            }
        } else {
            $storedId = $session->get('school.season_id');
            if ($storedId) {
                return $this->redirectToRoute('school_members_list', ['type' => $type, 'season' => $storedId]);
            }
            $season = null;
        }

        $roleMap = [
            'students' => TeamRole::TeamStudent,
            'teachers' => TeamRole::TeamTeacher,
            'admins'   => TeamRole::TeamAdmin,
        ];

        $criteria = ['team' => $team, 'deletedAt' => null];
        if ($type !== 'all') {
            $criteria['role'] = $roleMap[$type];
        }

        $members = $this->em->getRepository(TeamProfile::class)->findBy($criteria);

        // Index TeamProfileSeason by teamProfileId for the current season
        $tpsMap = [];
        if ($season !== null && count($members) > 0) {
            $tpsList = $this->em->getRepository(TeamProfileSeason::class)->findBy([
                'seasonId' => $season->getId(),
            ]);
            foreach ($tpsList as $tps) {
                $tpsMap[$tps->getTeamProfileId()] = $tps;
            }
        }

        return $this->render('school/members/list.html.twig', [
            'team'    => $team,
            'type'    => $type,
            'members' => $members,
            'season'  => $season,
            'tpsMap'  => $tpsMap,
        ]);
    }

    #[Route('/detail/{id}', name: 'school_member_detail', methods: ['GET'])]
    public function detail(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $member = $this->em->getRepository(TeamProfile::class)->find($id);

        if ($member === null || $member->getTeam()?->getId() !== $team->getId() || $member->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Member not found.');
        }

        // Fetch TPS for current season
        $seasonId = $team->getCurrentSeasonId();
        $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;
        $tps      = null;
        if ($season !== null) {
            $tps = $this->em->getRepository(TeamProfileSeason::class)->findOneBy([
                'teamProfileId' => $member->getId(),
                'seasonId'      => $season->getId(),
            ]);
        }

        return $this->render('school/members/detail.html.twig', [
            'team'   => $team,
            'member' => $member,
            'season' => $season,
            'tps'    => $tps,
        ]);
    }

    #[Route('/detail/{id}/edit', name: 'school_member_edit', methods: ['POST'])]
    public function edit(string $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        $member = $this->em->getRepository(TeamProfile::class)->find($id);

        if ($member === null || $member->getTeam()?->getId() !== $team->getId() || $member->getDeletedAt() !== null) {
            throw $this->createNotFoundException('Member not found.');
        }

        $f = $request->request;

        // Update Profile
        $profile = $member->getProfile();
        if ($profile !== null) {
            $profile->setFirstName(trim((string) $f->get('first_name', $profile->getFirstName())));
            $profile->setLastName(trim((string) $f->get('last_name', $profile->getLastName())));
            $profile->setPhone($f->get('phone') ?: null);
            $profile->setAddressText($f->get('address_text') ?: null);

            $genderVal = $f->get('gender');
            $profile->setGender($genderVal ? Gender::from($genderVal) : null);

            $dobVal = $f->get('dob');
            if ($dobVal) {
                $dob = \DateTimeImmutable::createFromFormat('Y-m-d', $dobVal);
                if ($dob !== false) {
                    $profile->setDob($dob);
                }
            } else {
                $profile->setDob(null);
            }
        }

        // Update note
        $member->setNote($f->get('note') ?: null);

        // Update or create TeamProfileSeason
        $seasonId = $team->getCurrentSeasonId();
        $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;

        if ($season !== null) {
            $tps = $this->em->getRepository(TeamProfileSeason::class)->findOneBy([
                'teamProfileId' => $member->getId(),
                'seasonId'      => $season->getId(),
            ]);

            if ($tps === null) {
                $tps = new TeamProfileSeason();
                $tps->setTeamProfileId($member->getId());
                $tps->setSeasonId($season->getId());
                $tps->setTeamId($team->getId());
                $this->em->persist($tps);
            }

            $regStatus = $f->get('registration_status');
            if ($regStatus) {
                $tps->setRegistrationStatus(RegistrationStatus::from($regStatus));
            }
            $tps->setInjuryWarning($f->get('injury_warning') ?: null);

            $accepted = $f->all('accepted');
            $tps->setAccepted($accepted ?: null);

            $ecName = $f->get('emergency_name');
            if ($ecName || $f->get('emergency_phone')) {
                $tps->setEmergencyContact([
                    'name'         => $f->get('emergency_name', ''),
                    'relationship' => $f->get('emergency_relationship', ''),
                    'email'        => $f->get('emergency_email', ''),
                    'phone'        => $f->get('emergency_phone', ''),
                ]);
            }
        }

        $this->em->flush();
        $this->addFlash('success', 'Fiche mise à jour.');

        return $this->redirectToRoute('school_member_detail', ['id' => $id]);
    }

    #[Route('/{type}/create', name: 'school_members_create', methods: ['GET', 'POST'],
        requirements: ['type' => 'students|teachers|admins'])]
    public function create(Request $request, string $type): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $team        = $this->teamContext->getCurrentTeam();
        $teamProfile = $this->teamContext->getCurrentTeamProfile($user);

        if ($team === null || $teamProfile === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::UPDATE, $team);

        if ($request->isMethod('POST')) {
            $seasonId = $team->getCurrentSeasonId();
            $season   = $seasonId ? $this->em->getRepository(Season::class)->find($seasonId) : null;

            if ($season === null) {
                throw $this->createNotFoundException('No active season found.');
            }

            $roleMap = [
                'students' => TeamRole::TeamStudent,
                'teachers' => TeamRole::TeamTeacher,
                'admins'   => TeamRole::TeamAdmin,
            ];

            $f = $request->request;

            // Required field validation
            $errors = [];
            if (!trim((string) $f->get('first_name', ''))) {
                $errors[] = 'Le prénom est obligatoire.';
            }
            if (!trim((string) $f->get('last_name', ''))) {
                $errors[] = 'Le nom est obligatoire.';
            }
            if (!$f->get('dob')) {
                $errors[] = 'La date de naissance est obligatoire.';
            }
            if (!$f->get('phone')) {
                $errors[] = 'Le téléphone est obligatoire.';
            }
            if (!$f->get('email')) {
                $errors[] = 'L\'email est obligatoire.';
            }
            if (!$f->get('address_text')) {
                $errors[] = 'L\'adresse est obligatoire.';
            }
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
                return $this->render('school/members/create.html.twig', ['team' => $team, 'type' => $type]);
            }

            $dobVal = $f->get('dob');
            $dob    = null;
            if ($dobVal) {
                $d = \DateTimeImmutable::createFromFormat('Y-m-d', $dobVal);
                if ($d !== false) {
                    $dob = $d;
                }
            }

            $genderVal = $f->get('gender');
            $gender    = $genderVal ? Gender::tryFrom($genderVal) : null;

            $regStatus    = $f->get('registration_status');
            $regStatusVal = $regStatus ? RegistrationStatus::tryFrom($regStatus) : null;

            $accepted = $f->all('accepted');

            $ecName = $f->get('emergency_name');
            $emergencyContact = null;
            if ($ecName || $f->get('emergency_phone')) {
                $emergencyContact = [
                    'name'         => $f->get('emergency_name', ''),
                    'relationship' => $f->get('emergency_relationship', ''),
                    'email'        => $f->get('emergency_email', ''),
                    'phone'        => $f->get('emergency_phone', ''),
                ];
            }

            $this->memberService->createMember($team, $season, [
                'firstName'          => trim((string) $f->get('first_name', '')),
                'lastName'           => trim((string) $f->get('last_name', '')),
                'dob'                => $dob,
                'phone'              => $f->get('phone') ?: null,
                'gender'             => $gender,
                'addressText'        => $f->get('address_text') ?: null,
                'note'               => $f->get('note') ?: null,
                'registrationStatus' => $regStatusVal,
                'injuryWarning'      => $f->get('injury_warning') ?: null,
                'emergencyContact'   => $emergencyContact,
                'accepted'           => $accepted ?: null,
                'role'               => $roleMap[$type],
            ]);

            $this->addFlash('success', 'Membre ajouté avec succès.');

            return $this->redirectToRoute('school_members_list', ['type' => $type]);
        }

        return $this->render('school/members/create.html.twig', [
            'team' => $team,
            'type' => $type,
        ]);
    }
}
