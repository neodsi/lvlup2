<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Season;
use App\Entity\School;
use App\Entity\SchoolUser;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolUserRepository;
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
        private readonly SchoolUserRepository $schoolUserRepository,
        private readonly MemberService $memberService,
    ) {
    }

    /**
     * POST /api/v1/schools/{schoolId}/school-profiles/create
     * Create a school member. Requires admin.
     */
    #[Route('/api/v1/schools/{schoolId}/school-profiles/create', name: 'api_v1_teams_team_profiles_create', methods: ['POST'])]
    public function create(string $schoolId, Request $request): JsonResponse
    {
        $authResponse = $this->requireSchoolAdmin($schoolId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $school = $this->em->getRepository(School::class)->find($schoolId);

        if ($school === null) {
            return new JsonResponse(['success' => false, 'error' => 'School not found.'], 404);
        }

        $data     = json_decode($request->getContent(), true) ?? [];
        $seasonId = $data['seasonId'] ?? null;

        $season = $seasonId !== null
            ? $this->em->getRepository(Season::class)->find($seasonId)
            : null;

        if ($season === null && $school->getCurrentSeasonId() !== null) {
            $season = $this->em->getRepository(Season::class)->find($school->getCurrentSeasonId());
        }

        if ($season === null) {
            return new JsonResponse(['success' => false, 'error' => 'Season not found or school has no current season.'], 422);
        }

        try {
            $schoolProfile = $this->memberService->createMember($school, $season, $data);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success'       => true,
            'schoolProfileId' => $schoolProfile->getId(),
        ], 201);
    }

    /**
     * POST /api/v1/schools/{schoolId}/school-profiles/{id}/update
     * Update a school member. Requires admin.
     */
    #[Route('/api/v1/schools/{schoolId}/school-profiles/{id}/update', name: 'api_v1_teams_team_profiles_update', methods: ['POST'])]
    public function update(string $schoolId, string $id, Request $request): JsonResponse
    {
        $authResponse = $this->requireSchoolAdmin($schoolId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $schoolUser = $this->em->getRepository(SchoolUser::class)->find($id);

        if ($schoolUser === null) {
            return new JsonResponse(['success' => false, 'error' => 'SchoolProfile not found.'], 404);
        }

        $school = $this->em->getRepository(School::class)->find($schoolId);

        if ($school === null || $schoolUser->getSchool()->getId() !== $schoolId) {
            return new JsonResponse(['success' => false, 'error' => 'SchoolProfile does not belong to this school.'], 403);
        }

        $data    = json_decode($request->getContent(), true) ?? [];
        $profile = $schoolUser->getProfile();

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
                $role = $data['role'] instanceof \App\Enum\SchoolRole
                    ? $data['role']
                    : \App\Enum\SchoolRole::from($data['role']);
                $schoolUser->setRole($role);
                $this->em->persist($schoolUser);
            }

            $this->em->flush();
        } catch (\ValueError $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'schoolProfileId' => $schoolUser->getId()]);
    }

    /**
     * POST /api/v1/schools/{schoolId}/school-profiles/export
     * Export school members as CSV. Requires admin.
     */
    #[Route('/api/v1/schools/{schoolId}/school-profiles/export', name: 'api_v1_teams_team_profiles_export', methods: ['POST'])]
    public function export(string $schoolId, Request $request): Response
    {
        $authResponse = $this->requireSchoolAdmin($schoolId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        $school = $this->em->getRepository(School::class)->find($schoolId);

        if ($school === null) {
            return new JsonResponse(['success' => false, 'error' => 'School not found.'], 404);
        }

        $data     = json_decode($request->getContent(), true) ?? [];
        $seasonId = $data['seasonId'] ?? $school->getCurrentSeasonId();

        $season = $seasonId !== null ? $this->em->getRepository(Season::class)->find($seasonId) : null;

        if ($season === null) {
            return new JsonResponse(['success' => false, 'error' => 'Season not found.'], 422);
        }

        $csv = $this->memberService->exportCsv($school, $season);

        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="members-%s.csv"', $schoolId),
        ]);
    }

    private function requireSchoolAdmin(string $schoolId): ?JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $schoolUser = $this->schoolUserRepository->findOneByUserAndSchool($user, $schoolId);

        if ($schoolUser === null) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $isAdmin = \in_array($schoolUser->getRole(), [SchoolRole::School, SchoolRole::School], true);

        if (!$isAdmin) {
            return new JsonResponse(['success' => false, 'error' => 'admin role required.'], 403);
        }

        return null;
    }
}
