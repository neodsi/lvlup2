<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\School;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Repository\SchoolProfileRepository;
use App\Service\Payment\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class StripeApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SchoolProfileRepository $schoolProfileRepository,
        private readonly StripeService $stripeService,
    ) {
    }

    /**
     * POST /api/v1/schools/{schoolId}/stripe/create-connected-account
     * Create a Stripe Connect Express account for the school. Requires admin.
     */
    #[Route('/api/v1/schools/{schoolId}/stripe/create-connected-account', name: 'api_v1_stripe_create_connected_account', methods: ['POST'])]
    public function createConnectedAccount(string $schoolId): JsonResponse
    {
        $response = $this->requireSchoolAdmin($schoolId, $school);
        if ($response !== null) {
            return $response;
        }

        try {
            $accountId = $this->stripeService->createConnectedAccount($school);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'stripeAccountId' => $accountId], 201);
    }

    /**
     * POST /api/v1/schools/{schoolId}/stripe/get-onboarding-link
     * Return the Stripe Connect onboarding URL. Requires admin.
     */
    #[Route('/api/v1/schools/{schoolId}/stripe/get-onboarding-link', name: 'api_v1_stripe_get_onboarding_link', methods: ['POST'])]
    public function getOnboardingLink(string $schoolId): JsonResponse
    {
        $response = $this->requireSchoolAdmin($schoolId, $school);
        if ($response !== null) {
            return $response;
        }

        try {
            $url = $this->stripeService->getOnboardingLink($school);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'url' => $url]);
    }

    /**
     * POST /api/v1/schools/{schoolId}/stripe/get-requirements
     * Fetch Stripe account requirements. Requires admin.
     */
    #[Route('/api/v1/schools/{schoolId}/stripe/get-requirements', name: 'api_v1_stripe_get_requirements', methods: ['POST'])]
    public function getRequirements(string $schoolId): JsonResponse
    {
        $response = $this->requireSchoolAdmin($schoolId, $school);
        if ($response !== null) {
            return $response;
        }

        try {
            $requirements = $this->stripeService->getRequirements($school);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'requirements' => $requirements]);
    }

    /**
     * POST /api/v1/schools/{schoolId}/stripe/update-account-status
     * Sync the Stripe account status from Stripe to the DB. Requires admin.
     */
    #[Route('/api/v1/schools/{schoolId}/stripe/update-account-status', name: 'api_v1_stripe_update_account_status', methods: ['POST'])]
    public function updateAccountStatus(string $schoolId): JsonResponse
    {
        $response = $this->requireSchoolAdmin($schoolId, $school);
        if ($response !== null) {
            return $response;
        }

        try {
            $this->stripeService->updateAccountStatus($school);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse([
            'success'             => true,
            'stripeAccountStatus' => $school->getStripeAccountStatus()->value,
        ]);
    }

    /**
     * Verify the current user is a admin of the given school.
     * Sets $school via reference on success.
     */
    private function requireSchoolAdmin(string $schoolId, ?School &$school = null): ?JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $school = $this->em->getRepository(School::class)->find($schoolId);

        if ($school === null) {
            return new JsonResponse(['success' => false, 'error' => 'School not found.'], 404);
        }

        $schoolProfile = $this->schoolProfileRepository->findOneByUserAndSchool($user, $schoolId);

        if ($schoolProfile === null) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $isAdmin = \in_array($schoolProfile->getRole(), [SchoolRole::School, SchoolRole::School], true);

        if (!$isAdmin) {
            return new JsonResponse(['success' => false, 'error' => 'admin role required.'], 403);
        }

        return null;
    }
}
