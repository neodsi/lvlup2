<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\TeamProfilePackage;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Service\Member\MemberService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class FastCountApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeamProfileRepository $teamProfileRepository,
        private readonly MemberService $memberService,
    ) {
    }

    /**
     * POST /api/v1/teams/{teamId}/team-profile-packages/{id}/fast-count/remove-one
     * Decrement one class from the package count. Requires team_teacher or higher.
     */
    #[Route(
        '/api/v1/teams/{teamId}/team-profile-packages/{id}/fast-count/remove-one',
        name: 'api_v1_fast_count_remove_one',
        methods: ['POST'],
    )]
    public function removeOne(string $teamId, string $id): JsonResponse
    {
        $authResponse = $this->requireTeamTeacher($teamId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        return $this->handleFastCount($teamId, $id, 'remove-one');
    }

    /**
     * POST /api/v1/teams/{teamId}/team-profile-packages/{id}/fast-count/cancel-remove
     * Cancel the last remove-one within the allowed window. Requires team_teacher or higher.
     */
    #[Route(
        '/api/v1/teams/{teamId}/team-profile-packages/{id}/fast-count/cancel-remove',
        name: 'api_v1_fast_count_cancel_remove',
        methods: ['POST'],
    )]
    public function cancelRemove(string $teamId, string $id): JsonResponse
    {
        $authResponse = $this->requireTeamTeacher($teamId);
        if ($authResponse !== null) {
            return $authResponse;
        }

        return $this->handleFastCount($teamId, $id, 'cancel-remove');
    }

    private function handleFastCount(string $teamId, string $id, string $action): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $package = $this->em->getRepository(TeamProfilePackage::class)->find($id);

        if ($package === null || $package->getTeamId() !== $teamId) {
            return new JsonResponse(['success' => false, 'error' => 'TeamProfilePackage not found.'], 404);
        }

        try {
            $updated = $this->memberService->fastCount($package, $user, $action);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success'     => true,
            'classesDone' => $updated->getClassesDone(),
            'classesQty'  => $updated->getClassesQty(),
            'status'      => $updated->getStatus()->value,
        ]);
    }

    private function requireTeamTeacher(string $teamId): ?JsonResponse
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

        $allowed = [TeamRole::TeamTeacher, TeamRole::TeamAdmin, TeamRole::TeamOwner];

        if (!\in_array($teamProfile->getRole(), $allowed, true)) {
            return new JsonResponse(['success' => false, 'error' => 'team_teacher role or higher required.'], 403);
        }

        return null;
    }
}
