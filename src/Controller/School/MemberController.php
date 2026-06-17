<?php

declare(strict_types=1);

namespace App\Controller\School;

use App\Entity\Season;
use App\Entity\TeamProfile;
use App\Entity\User;
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
        requirements: ['type' => 'students|teachers|admins'])]
    public function list(string $type): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $team = $this->teamContext->getCurrentTeam();

        if ($team === null || $this->teamContext->getCurrentTeamProfile($user) === null) {
            throw $this->createAccessDeniedException('Not a team member.');
        }

        $this->denyAccessUnlessGranted(TeamVoter::VIEW, $team);

        $roleMap = [
            'students' => TeamRole::TeamStudent,
            'teachers' => TeamRole::TeamTeacher,
            'admins'   => TeamRole::TeamAdmin,
        ];

        $members = $this->em->getRepository(TeamProfile::class)->findBy([
            'team'      => $team,
            'role'      => $roleMap[$type],
            'deletedAt' => null,
        ]);

        return $this->render('school/members/list.html.twig', [
            'team'    => $team,
            'type'    => $type,
            'members' => $members,
        ]);
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

            $this->memberService->createMember($team, $season, array_merge(
                $request->request->all(),
                ['role' => $roleMap[$type]],
            ));

            $this->addFlash('success', 'Member created successfully.');

            return $this->redirectToRoute('school_members_list', ['type' => $type]);
        }

        return $this->render('school/members/create.html.twig', [
            'team' => $team,
            'type' => $type,
        ]);
    }
}
