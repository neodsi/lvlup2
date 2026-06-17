<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamProfileRepository;
use App\Service\Payment\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class StripeApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TeamProfileRepository $teamProfileRepository,
        private readonly StripeService $stripeService,
    ) {
    }

    /**
     * POST /api/v1/teams/{teamId}/stripe/create-connected-account
     * Create a Stripe Connect Express account for the team. Requires team_admin.
     */
    #[Route('/api/v1/teams/{teamId}/stripe/create-connected-account', name: 'api_v1_stripe_create_connected_account', methods: ['POST'])]
    public function createConnectedAccount(string $teamId): JsonResponse
    {
        $response = $this->requireTeamAdmin($teamId, $team);
        if ($response !== null) {
            return $response;
        }

        try {
            $accountId = $this->stripeService->createConnectedAccount($team);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'stripeAccountId' => $accountId], 201);
    }

    /**
     * POST /api/v1/teams/{teamId}/stripe/get-onboarding-link
     * Return the Stripe Connect onboarding URL. Requires team_admin.
     */
    #[Route('/api/v1/teams/{teamId}/stripe/get-onboarding-link', name: 'api_v1_stripe_get_onboarding_link', methods: ['POST'])]
    public function getOnboardingLink(string $teamId): JsonResponse
    {
        $response = $this->requireTeamAdmin($teamId, $team);
        if ($response !== null) {
            return $response;
        }

        try {
            $url = $this->stripeService->getOnboardingLink($team);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'url' => $url]);
    }

    /**
     * POST /api/v1/teams/{teamId}/stripe/get-requirements
     * Fetch Stripe account requirements. Requires team_admin.
     */
    #[Route('/api/v1/teams/{teamId}/stripe/get-requirements', name: 'api_v1_stripe_get_requirements', methods: ['POST'])]
    public function getRequirements(string $teamId): JsonResponse
    {
        $response = $this->requireTeamAdmin($teamId, $team);
        if ($response !== null) {
            return $response;
        }

        try {
            $requirements = $this->stripeService->getRequirements($team);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'requirements' => $requirements]);
    }

    /**
     * POST /api/v1/teams/{teamId}/stripe/update-account-status
     * Sync the Stripe account status from Stripe to the DB. Requires team_admin.
     */
    #[Route('/api/v1/teams/{teamId}/stripe/update-account-status', name: 'api_v1_stripe_update_account_status', methods: ['POST'])]
    public function updateAccountStatus(string $teamId): JsonResponse
    {
        $response = $this->requireTeamAdmin($teamId, $team);
        if ($response !== null) {
            return $response;
        }

        try {
            $this->stripeService->updateAccountStatus($team);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success'             => true,
            'stripeAccountStatus' => $team->getStripeAccountStatus()->value,
        ]);
    }

    /**
     * Verify the current user is a team_admin of the given team.
     * Sets $team via reference on success.
     */
    private function requireTeamAdmin(string $teamId, ?Team &$team = null): ?JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $team = $this->em->getRepository(Team::class)->find($teamId);

        if ($team === null) {
            return new JsonResponse(['success' => false, 'error' => 'Team not found.'], 404);
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
