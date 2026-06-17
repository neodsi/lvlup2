<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Season;
use App\Entity\Team;
use App\Entity\TeamProfile;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Service\Member\MemberService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MemberApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeamProfileRepository $teamProfileRepository,
        private readonly MemberService $memberService,
    ) {
    }

    /**
     * POST /api/v1/teams/{teamId}/team-profiles/create
     * Create a team member. Requires team_admin.
     */
    #[Route('/api/v1/teams/{teamId}/team-profiles/create', name: 'api_v1_teams_team_profiles_create', methods: ['POST'])]
    public function create(string $teamId, Request $request): JsonResponse
    {
        $authResponse = $this->requireTeamAdmin($teamId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $team = $this->em->getRepository(Team::class)->find($teamId);

        if ($team === null) {
            return new JsonResponse(['success' => false, 'error' => 'Team not found.'], 404);
        }

        $data     = json_decode($request->getContent(), true) ?? [];
        $seasonId = $data['seasonId'] ?? null;

        $season = $seasonId !== null
            ? $this->em->getRepository(Season::class)->find($seasonId)
            : null;

        if ($season === null && $team->getCurrentSeasonId() !== null) {
            $season = $this->em->getRepository(Season::class)->find($team->getCurrentSeasonId());
        }

        if ($season === null) {
            return new JsonResponse(['success' => false, 'error' => 'Season not found or team has no current season.'], 422);
        }

        try {
            $teamProfile = $this->memberService->createMember($team, $season, $data);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success'       => true,
            'teamProfileId' => $teamProfile->getId(),
        ], 201);
    }

    /**
     * POST /api/v1/teams/{teamId}/team-profiles/{id}/update
     * Update a team member. Requires team_admin.
     */
    #[Route('/api/v1/teams/{teamId}/team-profiles/{id}/update', name: 'api_v1_teams_team_profiles_update', methods: ['POST'])]
    public function update(string $teamId, string $id, Request $request): JsonResponse
    {
        $authResponse = $this->requireTeamAdmin($teamId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $teamProfile = $this->em->getRepository(TeamProfile::class)->find($id);

        if ($teamProfile === null) {
            return new JsonResponse(['success' => false, 'error' => 'TeamProfile not found.'], 404);
        }

        $team = $this->em->getRepository(Team::class)->find($teamId);

        if ($team === null || $teamProfile->getTeam()->getId() !== $teamId) {
            return new JsonResponse(['success' => false, 'error' => 'TeamProfile does not belong to this team.'], 403);
        }

        $data    = json_decode($request->getContent(), true) ?? [];
        $profile = $teamProfile->getProfile();

        try {
            if ($profile !== null) {
                if (isset($data['firstName'])) {
                    $profile->setFirstName($data['firstName']);
                }
                if (isset($data['lastName'])) {
                    $profile->setLastName($data['lastName']);
                }
                if (isset($data['phone'])) {
                    $profile->setPhone($data['phone']);
                }
                if (isset($data['dob'])) {
                    $profile->setDob($data['dob']);
                }
                if (isset($data['gender'])) {
                    $profile->setGender($data['gender']);
                }
                if (isset($data['addressText'])) {
                    $profile->setAddressText($data['addressText']);
                }

                $this->em->persist($profile);
            }

            if (isset($data['role'])) {
                $role = $data['role'] instanceof \App\Enum\TeamRole
                    ? $data['role']
                    : \App\Enum\TeamRole::from($data['role']);
                $teamProfile->setRole($role);
                $this->em->persist($teamProfile);
            }

            $this->em->flush();
        } catch (\ValueError $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'teamProfileId' => $teamProfile->getId()]);
    }

    /**
     * POST /api/v1/teams/{teamId}/team-profiles/export
     * Export team members as CSV. Requires team_admin.
     */
    #[Route('/api/v1/teams/{teamId}/team-profiles/export', name: 'api_v1_teams_team_profiles_export', methods: ['POST'])]
    public function export(string $teamId, Request $request): Response
    {
        $authResponse = $this->requireTeamAdmin($teamId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $team = $this->em->getRepository(Team::class)->find($teamId);

        if ($team === null) {
            return new JsonResponse(['success' => false, 'error' => 'Team not found.'], 404);
        }

        $data     = json_decode($request->getContent(), true) ?? [];
        $seasonId = $data['seasonId'] ?? $team->getCurrentSeasonId();

        $season = $seasonId !== null ? $this->em->getRepository(Season::class)->find($seasonId) : null;

        if ($season === null) {
            return new JsonResponse(['success' => false, 'error' => 'Season not found.'], 422);
        }

        $csv = $this->memberService->exportCsv($team, $season);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="members-%s.csv"', $teamId),
        ]);
    }

    private function requireTeamAdmin(string $teamId): ?JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $teamProfile = $this->teamProfileRepository->findOneByUserAndTeam($user, $teamId);

        if ($teamProfile === null) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $isAdmin = \in_array($teamProfile->getRole(), [TeamRole::TeamAdmin, TeamRole::TeamOwner], true);

        if (!$isAdmin) {
            return new JsonResponse(['success' => false, 'error' => 'team_admin role required.'], 403);
        }

        return null;
    }
}
