<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Payment;
use App\Entity\PaymentSchedule;
use App\Entity\Profile;
use App\Entity\School;
use App\Entity\SchoolProfileSeason;
use App\Entity\User;
use App\Enum\SchoolRole;
use App\Service\Payment\StripeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class PaymentApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StripeService $stripeService,
    ) {
    }

    /**
     * POST /api/v1/payments/stripe/get-payment-link-for-schedules
     * Generate a Stripe payment link for a list of existing PaymentSchedule IDs.
     */
    #[Route('/api/v1/payments/stripe/get-payment-link-for-schedules', name: 'api_v1_payments_get_payment_link_for_schedules', methods: ['POST'])]
    public function getPaymentLinkForSchedules(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $data        = json_decode($request->getContent(), true) ?? [];
        $scheduleIds = $data['scheduleIds'] ?? [];
        $schoolId      = $data['schoolId'] ?? null;
        $profileId   = $data['profileId'] ?? null;

        if (empty($scheduleIds) || $schoolId === null || $profileId === null) {
            return new JsonResponse(['success' => false, 'error' => 'scheduleIds, schoolId and profileId are required.'], 422);
        }

        $school = $this->em->getRepository(School::class)->find($schoolId);

        if ($school === null) {
            return new JsonResponse(['success' => false, 'error' => 'School not found.'], 404);
        }

        $profile = $this->em->getRepository(Profile::class)->find($profileId);

        if ($profile === null) {
            return new JsonResponse(['success' => false, 'error' => 'Profile not found.'], 404);
        }

        try {
            $url = $this->stripeService->getPaymentLinkForSchedules($scheduleIds, $school, $profile);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\LogicException|\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'url' => $url]);
    }

    /**
     * POST /api/v1/payments/stripe/refund/{paymentId}
     * Refund a payment. Requires admin and verifies the payment belongs to the school.
     */
    #[Route('/api/v1/payments/stripe/refund/{paymentId}', name: 'api_v1_payments_stripe_refund', methods: ['POST'])]
    public function refund(string $paymentId, Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user === null) {
            return new JsonResponse(['success' => false, 'error' => 'Unauthenticated.'], 401);
        }

        $payment = $this->em->getRepository(Payment::class)->find($paymentId);

        if ($payment === null) {
            return new JsonResponse(['success' => false, 'error' => 'Payment not found.'], 404);
        }

        // Verify user is admin of the school that owns the payment
        $school = $this->em->getRepository(School::class)->find($payment->getSchoolId());
        $profile = $user->getProfile();

        $isAdmin = $profile !== null && $school !== null && (
            ($school->getOwnerProfileId() !== null && $school->getOwnerProfileId() === $profile->getId()) ||
            $this->em->getRepository(SchoolProfileSeason::class)->findOneBy([
                'profileId' => $profile->getId(),
                'schoolId'  => $payment->getSchoolId(),
                'role'      => SchoolRole::School,
            ]) !== null
        );

        if (!$isAdmin) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden or admin role required.'], 403);
        }

        if ($school === null) {
            return new JsonResponse(['success' => false, 'error' => 'School not found.'], 404);
        }

        $data   = json_decode($request->getContent(), true) ?? [];
        $amount = isset($data['amount']) ? (int) $data['amount'] : $payment->getAmount();

        try {
            $this->stripeService->refundPayment($payment, $amount, $school);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return new JsonResponse(['success' => true, 'refundedAmount' => $amount]);
    }
}
